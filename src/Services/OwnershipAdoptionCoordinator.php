<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipMigrationReview;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use PDO;

/**
 * Coordinates authorized, audited, resumable package adoption without transactional DDL assumptions.
 *
 * @api
 *
 * @phpstan-import-type Graph from OwnershipAdoptionGraph
 * @phpstan-import-type Run from OwnershipAdoptionStore
 * @phpstan-import-type Checkpoints from OwnershipAdoptionStore
 * @phpstan-import-type Snapshot from OwnershipAdoptionConservation
 */
final readonly class OwnershipAdoptionCoordinator
{
    /** Create the stable write boundary for adoption runs and installation markers. */
    public function __construct(
        private Container $container,
        private Repository $configuration,
        private OwnershipConnections $connections,
        private OwnershipAdoptionGraph $graphs,
        private OwnershipAdoptionStore $store,
        private OwnershipAdoptionLock $lock,
        private OwnershipConfigurationFingerprint $ownership,
        private OwnershipOperationRecorder $audit,
        private OwnershipAdoptionScope $scope,
        private OwnershipAdoptionConservation $conservation,
        private OwnershipMigrationInput $migrationInput,
    ) {}

    /**
     * Persist immutable reviewed input and block the selected graph before invoking package DDL.
     *
     * @param  list<string>  $packages
     * @param  iterable<OwnershipMapping>  $mappings
     */
    public function prepare(array $packages, iterable $mappings, OwnershipOperation $operation, ?OwnershipMigrationReview $review = null): OwnershipAdoptionPlan
    {
        return $this->mutate($operation, function () use ($packages, $mappings, $review): OwnershipAdoptionPlan {
            $graph = $this->graphs->resolve($packages);
            $connection = $this->connections->core();
            if ($review !== null) {
                $this->migrationInput->assertCurrent($review, $graph);
                if ($connection->table(Tables::Runs)->where('id', $review->id)->exists()) {
                    throw new OwnershipConfigurationInvalid('This plan is already persisted. Resume it with backfill, verify or activate; do not prepare it again.');
                }
            }
            $this->assertNoInterruptedRun($graph);
            $id = $review === null ? (string) Str::uuid() : $review->id;
            $this->assertSourceMarkers($id, $graph);
            $configurationHash = $this->ownership->hash($graph['resources']);
            $fingerprints = [];
            foreach ($graph['resources'] as $resource) {
                $fingerprints[$resource] = $this->ownership->fingerprint($resource);
            }
            $checkpoints = ['manifest' => $graph['manifest'], 'adapters' => [], 'fingerprints' => $fingerprints];
            foreach ($graph['packages'] as $package) {
                $checkpoints['adapters'][$package] = ['prepared' => false, 'cursor' => null, 'done' => false, 'activated' => false];
            }
            $plan = $this->scope->preparation($id, fn () => $connection->transaction(function () use ($id, $configurationHash, $graph, $checkpoints, $mappings, $connection): OwnershipAdoptionPlan {
                $connection->table(Tables::Runs)->insert([
                    'id' => $id, 'status' => 'prepared', 'mapping_hash' => hash('sha256', ''), 'configuration_hash' => $configurationHash,
                    'packages' => json_encode($graph['packages'], JSON_THROW_ON_ERROR), 'checkpoints' => json_encode($checkpoints, JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $mappingHash = $this->store->ingest($id, $mappings, $graph);
                $connection->table(Tables::Runs)->where('id', $id)->update(['mapping_hash' => $mappingHash]);

                return new OwnershipAdoptionPlan($id, $this->connections->name($connection->getName()), $mappingHash, $configurationHash);
            }));
            $this->prepareAdapters($plan, $graph, $checkpoints);

            return $plan;
        });
    }

    /** Run at most one bounded batch per selected adapter and persist stable progress. */
    public function backfill(OwnershipAdoptionPlan $plan, int $limit, OwnershipOperation $operation): bool
    {
        if ($limit < 1 || $limit > 10000) {
            throw new OwnershipConfigurationInvalid('Backfill limits must be between 1 and 10000.');
        }

        return $this->mutate($operation, function () use ($plan, $limit): bool {
            [$run, $graph] = $this->validated($plan);
            $this->assertNotRefreshing($run);
            if ($run['status'] === 'active') {
                return true;
            }
            $checkpoints = $run['checkpoints'];
            $this->prepareAdapters($plan, $graph, $checkpoints);
            foreach ($graph['adapters'] as $package => $adapter) {
                $checkpoint = $checkpoints['adapters'][$package];
                if ($checkpoint['done']) {
                    continue;
                }
                $result = $this->invoke($plan, $adapter, 'backfill', fn () => $adapter->backfill($plan, $checkpoint['cursor'], $limit));
                if ($result->processed < 0 || $result->processed > $limit || ($result->nextCursor !== null
                    && ($result->processed === 0 || $result->nextCursor === $checkpoint['cursor'] || $result->nextCursor === '' || strlen($result->nextCursor) > 191))) {
                    throw new OwnershipViolation("The [{$package}] adoption adapter returned invalid or stuck batch progress.");
                }
                $checkpoints['adapters'][$package] = [...$checkpoint, 'cursor' => $result->nextCursor, 'done' => $result->nextCursor === null];
                $this->store->checkpoint($plan->id, 'backfilling', $checkpoints);
                if ($result->nextCursor !== null) {
                    return false;
                }
            }
            $this->store->checkpoint($plan->id, 'backfilled', $checkpoints);

            return true;
        });
    }

    /** Inspect every package and actual schema without changing checkpoints or authorizing mutation. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $this->assertTransactionEntry();

        return $this->locked(function () use ($plan): OwnershipVerification {
            [$run, $graph] = $this->validated($plan);
            $this->assertNotRefreshing($run);

            return $this->verifyGraph($plan, $run, $graph, false);
        });
    }

    /** Apply all final constraints before publishing the entire graph as active together. */
    public function activate(OwnershipAdoptionPlan $plan, OwnershipOperation $operation): void
    {
        $this->mutate($operation, function () use ($plan): void {
            [$run, $graph] = $this->validated($plan);
            $this->assertNotRefreshing($run);
            if (! $this->verifyGraph($plan, $run, $graph)->passed()) {
                throw new OwnershipSchemaNotReady('The complete adoption graph must pass verification before activation.');
            }
            if ($run['status'] === 'active') {
                return;
            }
            $checkpoints = $run['checkpoints'];
            foreach ($graph['adapters'] as $package => $adapter) {
                // DDL may already have committed before an interruption: adapters must retry idempotently.
                $this->invoke($plan, $adapter, 'activate', fn () => $adapter->activate($plan));
                $checkpoints['adapters'][$package] = [...$checkpoints['adapters'][$package], 'activated' => true];
                $this->store->checkpoint($plan->id, 'activating', $checkpoints);
            }
            if (! $this->verifyGraph($plan, [...$run, 'checkpoints' => $checkpoints], $graph)->passed()) {
                throw new OwnershipSchemaNotReady('The final adoption schema failed verification.');
            }
            $connection = $this->connections->core();
            $pdo = $connection->getPdo();
            $this->validated($plan);
            $this->assertCallbackBoundary($connection, $pdo, true, true);
            foreach ($graph['resources'] as $resource) {
                $this->connections->resource($resource)->table(Tables::Markers)->where('resource', $resource)->where('generation', $plan->id)->update(['state' => 'active', 'updated_at' => now()]);
            }
            $connection->transaction(function () use ($plan, $checkpoints): void {
                $this->store->checkpoint($plan->id, 'active', $checkpoints);
            });
        });
    }

    /**
     * Reapply registered constraint callbacks without changing mappings, generations or resource rows.
     *
     * The operation purpose must be ownership.constraints.refresh and receive fresh host authorization.
     * Requires maintenance, no participating transaction, and the current persisted active plan.
     * Interrupted refreshes retain their original conservation snapshot and remain fenced until resumed.
     *
     * @api
     */
    public function refreshConstraints(OwnershipAdoptionPlan $plan, OwnershipOperation $operation): void
    {
        if ($operation->purpose !== 'ownership.constraints.refresh') {
            throw new OwnershipViolation('Constraint refresh requires its explicit ownership.constraints.refresh operation purpose.');
        }
        $this->mutate($operation, function () use ($plan): void {
            [$run, $graph] = $this->validated($plan, constraintRefresh: true);
            if (! in_array($run['status'], ['active', 'refreshing'], true)) {
                throw new OwnershipSchemaNotReady('Only an active graph or its interrupted constraint refresh can be refreshed.');
            }
            if ($graph['resources'] === []) {
                throw new OwnershipSchemaNotReady('Constraint refresh requires at least one registered resource.');
            }
            $this->assertNoInterruptedRun($graph, $plan->id);
            foreach ($run['checkpoints']['adapters'] as $checkpoint) {
                if (! $checkpoint['prepared'] || ! $checkpoint['done'] || ! $checkpoint['activated']) {
                    throw new OwnershipSchemaNotReady('Constraint refresh requires a completely activated adapter graph.');
                }
            }
            $snapshot = $this->refreshSnapshot($plan, $graph);
            $checkpoints = $run['checkpoints'];
            if ($run['status'] === 'active') {
                $checkpoints['constraint_refresh'] = $snapshot;
                $this->store->checkpoint($plan->id, 'refreshing', $checkpoints);
            } elseif (($checkpoints['constraint_refresh'] ?? null) !== $snapshot) {
                throw new OwnershipViolation('Constraint refresh differs from its original persisted conservation snapshot.');
            }
            $this->refreshMarkers($plan, $graph, 'prepared');
            foreach ($graph['adapters'] as $adapter) {
                $this->invoke($plan, $adapter, 'activate', fn () => $adapter->activate($plan), constraintRefresh: true);
            }
            if ($this->refreshSnapshot($plan, $graph) !== $checkpoints['constraint_refresh']) {
                throw new OwnershipViolation('Constraint refresh changed application resource rows.');
            }
            $this->refreshMarkers($plan, $graph, 'active');
            unset($checkpoints['constraint_refresh']);
            $this->store->checkpoint($plan->id, 'active', $checkpoints);
        });
    }

    /** Reload and validate immutable input without granting permission or performing DDL. */
    public function resume(string $runId): OwnershipAdoptionPlan
    {
        $this->assertTransactionEntry();

        return $this->locked(function () use ($runId): OwnershipAdoptionPlan {
            $run = $this->store->load($runId);
            $plan = $run['plan'];
            $this->validated($plan, constraintRefresh: in_array($run['status'], ['active', 'refreshing'], true));

            return $plan;
        });
    }

    /**
     * Revalidate actual preparation schema and persist completion only after each adapter succeeds.
     *
     * @param  Graph  $graph
     * @param  Checkpoints  $checkpoints
     */
    private function prepareAdapters(OwnershipAdoptionPlan $plan, array $graph, array &$checkpoints): void
    {
        $this->stampSources($plan, $graph);
        foreach ($graph['adapters'] as $package => $adapter) {
            $this->invoke($plan, $adapter, 'prepare', fn () => $adapter->prepare($plan));
            $checkpoints['adapters'][$package] = [...$checkpoints['adapters'][$package], 'prepared' => true];
            $this->store->checkpoint($plan->id, 'prepared', $checkpoints);
        }
    }

    /** @param Graph $graph */
    private function assertNoInterruptedRun(array $graph, ?string $except = null): void
    {
        $connection = $this->connections->core();
        foreach ($connection->table(Tables::Runs)->where('status', '!=', 'active')->get() as $run) {
            if ($except !== null && $run->id === $except) {
                continue;
            }
            if (! is_string($run->packages)) {
                throw new OwnershipConfigurationInvalid('Invalid adoption package history.');
            }
            $packages = json_decode($run->packages, true, 32, JSON_THROW_ON_ERROR);
            if (! is_array($packages) || count(array_filter($packages, 'is_string')) !== count($packages)) {
                throw new OwnershipConfigurationInvalid('Invalid adoption package history.');
            }
            if (array_intersect($graph['packages'], array_filter($packages, 'is_string')) !== []) {
                throw new OwnershipSchemaNotReady('A selected package has an interrupted adoption; resume its original run.');
            }
        }
    }

    /** @return array{Run, Graph} */
    private function validated(OwnershipAdoptionPlan $plan, bool $constraintRefresh = false): array
    {
        $run = $this->store->load($plan->id);
        if (! $constraintRefresh) {
            $this->assertNotRefreshing($run);
        }
        $graph = $this->graphs->resolve($run['packages']);
        $mappingHash = $constraintRefresh && in_array($run['status'], ['active', 'refreshing'], true)
            ? $this->store->immutableMappingHash($plan->id)
            : $this->store->mappingHash($plan->id, $graph);
        if ($run['plan'] != $plan || $graph['packages'] !== $run['packages'] || ! hash_equals($graph['manifest'], $run['checkpoints']['manifest'])
            || array_diff(array_keys($run['checkpoints']['adapters']), $run['packages']) !== []
            || array_diff($run['packages'], array_keys($run['checkpoints']['adapters'])) !== []
            || ! hash_equals($this->ownership->hash($graph['resources']), $plan->configurationHash)
            || ! hash_equals($mappingHash, $plan->mappingHash)
            || ! in_array($run['status'], ['prepared', 'backfilling', 'backfilled', 'activating', 'active', 'refreshing'], true)) {
            throw new OwnershipConfigurationInvalid('The adoption input, adapter graph, or ownership configuration changed.');
        }
        foreach ($graph['resources'] as $resource) {
            $connection = $this->connections->resource($resource);
            $definition = $this->container->make(OwnershipResourceRegistry::class)->get($resource);
            $table = (new $definition->model)->getTable();
            $marker = $connection->table(Tables::Markers)->useWritePdo()->where('resource', $resource)->first();
            if ($marker === null && ! $run['checkpoints']['adapters'][$graph['owners'][$resource]]['prepared']) {
                continue;
            }
            if ($marker === null || $marker->physical_table !== $table
                || $marker->generation !== $plan->id || $marker->configuration_hash !== $this->ownership->fingerprint($resource)
                || $marker->source_store !== $this->connections->identity($connection)
                || $marker->coordinator_store !== $this->connections->identity($this->connections->core())
                || ! in_array($marker->schema_version, [1, '1'], true)
                || ! in_array($marker->state, $run['status'] === 'active' ? ['active'] : ['prepared', 'active'], true)) {
                throw new OwnershipSchemaNotReady('The source generation changed during adoption.');
            }
        }

        return [$run, $graph];
    }

    /** @param Run $run */
    private function assertNotRefreshing(array $run): void
    {
        if ($run['status'] === 'refreshing') {
            throw new OwnershipSchemaNotReady('Resume the explicit constraint refresh before using adoption phases.');
        }
    }

    /**
     * @param  Graph  $graph
     * @return Snapshot
     */
    private function refreshSnapshot(OwnershipAdoptionPlan $plan, array $graph): array
    {
        $snapshot = [];
        foreach ($graph['adapters'] as $adapter) {
            $snapshot = [...$snapshot, ...$this->invoke($plan, $adapter, 'verify', fn (): array => $this->conservation->snapshot($adapter), constraintRefresh: true)];
        }
        ksort($snapshot, SORT_STRING);

        return $snapshot;
    }

    /** @param Graph $graph */
    private function refreshMarkers(OwnershipAdoptionPlan $plan, array $graph, string $state): void
    {
        foreach ($graph['resources'] as $resource) {
            $this->connections->resource($resource)->table(Tables::Markers)->where('resource', $resource)->where('generation', $plan->id)
                ->update(['state' => $state, 'updated_at' => now()]);
        }
        $this->validated($plan, constraintRefresh: true);
    }

    /**
     * Verify the graph without persisting an authorization-free verification flag.
     *
     * @param  Run  $run
     * @param  Graph  $graph
     */
    private function verifyGraph(OwnershipAdoptionPlan $plan, array $run, array $graph, bool $requiresMaintenance = true): OwnershipVerification
    {
        $errors = [];
        $reports = [];
        foreach ($graph['adapters'] as $package => $adapter) {
            if (! $run['checkpoints']['adapters'][$package]['prepared'] || ! $run['checkpoints']['adapters'][$package]['done']) {
                $errors[] = $package.':backfill_incomplete';

                continue;
            }
            $verification = $this->invoke($plan, $adapter, 'verify', function () use ($plan, $adapter): OwnershipVerification {
                $specific = $adapter->verify($plan);
                $conserved = $this->conservation->verify($plan, $adapter);

                return new OwnershipVerification([...$specific->errors, ...$conserved->errors], [...$specific->resources, ...$conserved->resources]);
            }, $requiresMaintenance);
            $reports = [...$reports, ...$verification->resources];
            foreach ($verification->errors as $error) {
                if (mb_strlen($error) > 255) {
                    throw new OwnershipConfigurationInvalid('Verification errors must be bounded codes or record identifiers.');
                }
                $errors[] = $package.':'.$error;
                if (count($errors) >= 100) {
                    return new OwnershipVerification($errors, $reports);
                }
            }
        }

        return new OwnershipVerification($errors, $reports);
    }

    /**
     * Confine a registered adapter invocation to its immutable run and live connection session.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function invoke(OwnershipAdoptionPlan $plan, OwnershipAdoptionAdapter $adapter, string $phase, Closure $callback, bool $requiresMaintenance = true, bool $constraintRefresh = false): mixed
    {
        $connection = $this->connections->core();
        $pdo = $connection->getPdo();
        $enabled = $this->configuration->get('nvl-ownership.enabled') === true;
        $maintenance = $this->container->make(MaintenanceMode::class)->active();
        if ($requiresMaintenance && (! $enabled || ! $maintenance)) {
            throw new OwnershipViolation('Adoption requires enabled ownership and application maintenance mode.');
        }
        $this->validated($plan, $constraintRefresh);
        $this->assertCallbackBoundary($connection, $pdo, $enabled, $maintenance);
        $sessions = [];
        foreach ($this->connections->participating() as $id => $source) {
            $sessions[$id] = $source->getPdo();
        }
        $result = $this->scope->during($plan->id, $adapter::class, $phase, $callback);
        foreach ($this->connections->participating() as $id => $source) {
            if (! isset($sessions[$id]) || $source->getRawPdo() !== $sessions[$id] || $source->transactionLevel() !== 0) {
                throw new OwnershipViolation('The adoption source session changed inside a callback.');
            }
        }
        $this->assertCallbackBoundary($connection, $pdo, $enabled, $maintenance);
        $this->validated($plan, $constraintRefresh);
        $this->assertCallbackBoundary($connection, $pdo, $enabled, $maintenance);

        return $result;
    }

    /** Preserve live connection and maintenance state across every package callback. */
    private function assertCallbackBoundary(Connection $connection, PDO $pdo, bool $enabled, bool $maintenance): void
    {
        if ($this->connections->core() !== $connection || $connection->getRawPdo() !== $pdo || $connection->transactionLevel() !== 0
            || ($this->configuration->get('nvl-ownership.enabled') === true) !== $enabled
            || $this->container->make(MaintenanceMode::class)->active() !== $maintenance) {
            throw new OwnershipViolation('The adoption maintenance or connection boundary changed inside an adapter.');
        }
    }

    /** Reject caller-owned transactions without changing their state or invoking package code. */
    private function assertTransactionEntry(): void
    {
        foreach ($this->connections->participating() as $connection) {
            if ($connection->transactionLevel() !== 0) {
                throw new OwnershipViolation('Adoption cannot enter inside a participating transaction.');
            }
        }
    }

    /** @param Graph $graph */
    private function stampSources(OwnershipAdoptionPlan $plan, array $graph): void
    {
        foreach ($graph['resources'] as $resource) {
            $connection = $this->connections->resource($resource);
            $definition = $this->container->make(OwnershipResourceRegistry::class)->get($resource);
            $table = (new $definition->model)->getTable();
            $existing = $connection->table(Tables::Markers)->where('resource', $resource)->orWhere('physical_table', $table)->first();
            if ($existing !== null) {
                $this->assertSourceMarker($existing, $plan->id, $resource, $table);

                continue;
            }
            $connection->table(Tables::Markers)->insert([
                'resource' => $resource, 'physical_table' => $table, 'state' => 'prepared', 'schema_version' => 1,
                'configuration_hash' => $this->ownership->fingerprint($resource), 'generation' => $plan->id,
                'coordinator_store' => $this->connections->identity($this->connections->core()), 'source_store' => $this->connections->identity($connection),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    /**
     * Reject a known marker conflict before persisting a new run or its mappings.
     *
     * @param  Graph  $graph
     */
    private function assertSourceMarkers(string $generation, array $graph): void
    {
        foreach ($graph['resources'] as $resource) {
            $definition = $this->container->make(OwnershipResourceRegistry::class)->get($resource);
            $table = (new $definition->model)->getTable();
            $existing = $this->connections->resource($resource)->table(Tables::Markers)->where('resource', $resource)->orWhere('physical_table', $table)->first();
            if ($existing !== null) {
                $this->assertSourceMarker($existing, $generation, $resource, $table);
            }
        }
    }

    /** Require the original physical resource, generation and immutable configuration. */
    private function assertSourceMarker(object $marker, string $generation, string $resource, string $table): void
    {
        $attributes = get_object_vars($marker);
        if (($attributes['resource'] ?? null) !== $resource || ($attributes['physical_table'] ?? null) !== $table || ($attributes['generation'] ?? null) !== $generation || ($attributes['configuration_hash'] ?? null) !== $this->ownership->fingerprint($resource)) {
            throw new OwnershipSchemaNotReady('Resume the original source adoption; marker replacement is forbidden.');
        }
    }

    /**
     * Lock every actual source in stable order without claiming a distributed transaction.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function locked(Closure $callback): mixed
    {
        $connections = $this->connections->sources();
        $ordered = [];
        foreach ($connections as $connection) {
            $ordered[$this->connections->identity($connection).':'.$connection->getName()] = $connection;
        }
        ksort($ordered, SORT_STRING);

        return $this->lockSources(array_values($ordered), $callback);
    }

    /**
     * Acquire one source session and release every lock in reverse order.
     *
     * @template T
     *
     * @param  list<Connection>  $sources
     * @param  Closure(): T  $callback
     * @return T
     */
    private function lockSources(array $sources, Closure $callback): mixed
    {
        $connection = array_shift($sources);

        return $connection === null ? $callback() : $this->lock->during($connection, fn () => $this->lockSources($sources, $callback));
    }

    /**
     * Authorize and durably audit each mutation under a session-preserving connection lock.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function mutate(OwnershipOperation $operation, Closure $callback): mixed
    {
        if ($this->configuration->get('nvl-ownership.enabled') !== true || ! $this->container->make(MaintenanceMode::class)->active()) {
            throw new OwnershipViolation('Adoption requires enabled ownership and application maintenance mode.');
        }
        $this->container->make(OwnershipAdoptionAccess::class)->authorize($operation);
        $this->assertTransactionEntry();

        return $this->locked(function () use ($operation, $callback): mixed {
            $this->audit->record($operation);

            return $callback();
        });
    }
}
