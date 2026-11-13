<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Support\LazyCollection;
use Illuminate\Support\Str;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Contracts\OwnershipAdoptionMetadataValidator;
use Nvl\Ownership\Contracts\OwnershipAdoptionSource;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Persists and validates immutable adoption inputs and bounded phase checkpoints.
 *
 * @internal
 *
 * @phpstan-import-type Graph from OwnershipAdoptionGraph
 * @phpstan-import-type Snapshot from OwnershipAdoptionConservation
 *
 * @phpstan-type Checkpoint array{prepared: bool, cursor: string|null, done: bool, activated: bool}
 * @phpstan-type Checkpoints array{manifest: string, adapters: array<string, Checkpoint>, fingerprints: array<string, string>, constraint_refresh?: Snapshot}
 * @phpstan-type Run array{plan: OwnershipAdoptionPlan, status: string, packages: list<string>, checkpoints: Checkpoints}
 */
final readonly class OwnershipAdoptionStore
{
    private const int SourceKeyBatchSize = 200;

    /** Resolve canonical storage and fresh native owner admission. */
    public function __construct(private OwnershipConnections $connections, private Container $container, private OwnershipAdoptionScope $scope) {}

    /** @return Run */
    public function load(string $id): array
    {
        $row = $this->connections->core()->table(Tables::Runs)->where('id', $id)->first();
        if ($row === null || ! is_string($row->mapping_hash) || ! is_string($row->configuration_hash)
            || ! is_string($row->packages) || ! is_string($row->checkpoints) || ! is_string($row->status)) {
            throw new OwnershipConfigurationInvalid('Unknown or invalid persisted adoption run.');
        }
        $packages = json_decode($row->packages, true, 32, JSON_THROW_ON_ERROR);
        $checkpoints = json_decode($row->checkpoints, true, 32, JSON_THROW_ON_ERROR);
        if (! is_array($packages) || ! array_is_list($packages) || $packages === [] || count(array_filter($packages, 'is_string')) !== count($packages)
            || ! is_array($checkpoints) || ! isset($checkpoints['manifest'], $checkpoints['adapters']) || ! is_string($checkpoints['manifest']) || ! is_array($checkpoints['adapters'])) {
            throw new OwnershipConfigurationInvalid('Invalid adoption input or checkpoint shape.');
        }
        $adapters = [];
        foreach ($checkpoints['adapters'] as $package => $checkpoint) {
            if (! is_string($package) || ! is_array($checkpoint) || ! is_bool($checkpoint['prepared'] ?? null)
                || ! array_key_exists('cursor', $checkpoint) || (! is_string($checkpoint['cursor']) && $checkpoint['cursor'] !== null)
                || ! is_bool($checkpoint['done'] ?? null) || ! is_bool($checkpoint['activated'] ?? null)) {
                throw new OwnershipConfigurationInvalid('Invalid adoption adapter checkpoint.');
            }
            $adapters[$package] = ['prepared' => $checkpoint['prepared'], 'cursor' => $checkpoint['cursor'], 'done' => $checkpoint['done'], 'activated' => $checkpoint['activated']];
        }
        if (! is_array($checkpoints['fingerprints'] ?? null)) {
            throw new OwnershipConfigurationInvalid('Missing immutable source fingerprints.');
        }
        $fingerprints = [];
        foreach ($checkpoints['fingerprints'] as $key => $fingerprint) {
            if (! is_string($key) || ! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1) {
                throw new OwnershipConfigurationInvalid('Invalid persisted source fingerprint.');
            }
            $fingerprints[$key] = $fingerprint;
        }

        $validated = ['manifest' => $checkpoints['manifest'], 'adapters' => $adapters, 'fingerprints' => $fingerprints];
        if (array_key_exists('constraint_refresh', $checkpoints)) {
            if ($row->status !== 'refreshing' || ! is_array($checkpoints['constraint_refresh']) || $checkpoints['constraint_refresh'] === []) {
                throw new OwnershipConfigurationInvalid('Invalid constraint refresh checkpoint.');
            }
            $snapshot = [];
            foreach ($checkpoints['constraint_refresh'] as $resource => $entry) {
                if (! is_string($resource) || ! is_array($entry) || ! is_string($entry['store'] ?? null) || $entry['store'] === ''
                    || ! is_string($entry['table'] ?? null) || $entry['table'] === '' || ! is_string($entry['key'] ?? null) || $entry['key'] === ''
                    || ! is_int($entry['rows'] ?? null) || $entry['rows'] < 0 || ! is_string($entry['digest'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $entry['digest']) !== 1) {
                    throw new OwnershipConfigurationInvalid('Invalid constraint refresh conservation snapshot.');
                }
                $snapshot[$resource] = ['store' => $entry['store'], 'table' => $entry['table'], 'key' => $entry['key'], 'rows' => $entry['rows'], 'digest' => $entry['digest']];
            }
            ksort($snapshot, SORT_STRING);
            $validated['constraint_refresh'] = $snapshot;
        } elseif ($row->status === 'refreshing') {
            throw new OwnershipConfigurationInvalid('A constraint refresh requires its original conservation snapshot.');
        }

        return ['plan' => new OwnershipAdoptionPlan($id, $this->connections->name($this->connections->core()->getName()), $row->mapping_hash, $row->configuration_hash), 'status' => $row->status, 'packages' => array_values(array_filter($packages, 'is_string')), 'checkpoints' => $validated];
    }

    /**
     * Validate and stream reviewed rows into indexed storage inside the caller's preparation transaction, then hash the sealed bytes.
     *
     * @param  iterable<OwnershipMapping>  $assignments
     * @param  Graph  $graph
     */
    public function ingest(string $runId, iterable $assignments, array $graph): string
    {
        foreach ($assignments as $assignment) {
            $metadata = $this->validateAssignment($runId, $assignment, $graph);
            $query = $this->connections->core()->table(Tables::Mappings);
            if ((clone $query)->where('run_id', $runId)->where('resource', $assignment->resource)->where('record_id', $assignment->recordId)->exists()) {
                throw new OwnershipConfigurationInvalid('Duplicate or conflicting record assignment.');
            }
            $query->insert(['run_id' => $runId, 'resource' => $assignment->resource, 'record_id' => $assignment->recordId, 'owner_reference' => json_encode($assignment->owner?->toArray(), JSON_THROW_ON_ERROR), 'legacy_tenant_id' => $assignment->legacyTenantId, 'metadata' => $metadata]);
        }

        return $this->immutableMappingHash($runId);
    }

    /**
     * Recompute sorted immutable mapping bytes and revalidate current owner/metadata admission.
     *
     * @param  Graph  $graph
     */
    public function mappingHash(string $runId, array $graph): string
    {
        return $this->hashMappings($runId, $graph);
    }

    /** Verify sealed mapping bytes without reauthorizing historical assignments or source inventory. */
    public function immutableMappingHash(string $runId): string
    {
        return $this->hashMappings($runId);
    }

    /** @param  Graph|null  $graph */
    private function hashMappings(string $runId, ?array $graph = null): string
    {
        $connection = $this->connections->core();
        $ordering = match ($connection->getDriverName()) {
            'pgsql' => 'resource COLLATE "C", record_id COLLATE "C"',
            default => 'resource COLLATE BINARY, record_id COLLATE BINARY',
        };
        $assignments = $connection->table(Tables::Mappings)->where('run_id', $runId)->orderByRaw($ordering)->cursor()
            ->map($this->decodeMapping(...));
        $canBatch = $graph !== null && ! array_any($graph['adapters'], static fn (object $adapter): bool => $adapter instanceof OwnershipAdoptionMetadataValidator || $adapter instanceof OwnershipAdoptionSource);
        $chunks = $canBatch
            ? $assignments->chunk(self::SourceKeyBatchSize)->map(static fn (LazyCollection $chunk): array => $chunk->all())
            : $assignments->map(static fn (OwnershipMapping $assignment): array => [$assignment]);
        $ownerStores = null;
        $hash = hash_init('sha256');
        foreach ($chunks as $chunk) {
            $sourceKeys = null;
            if ($graph !== null && $canBatch && $this->canBatchSourceKeys($chunk)) {
                $sourceKeys = $this->sourceKeys($chunk, $graph);
                $ownerStores ??= [];
            } else {
                $ownerStores = null;
            }
            foreach ($chunk as $assignment) {
                $canonical = $graph === null
                    ? $this->metadataBytes($assignment)
                    : $this->validateAssignment($runId, $assignment, $graph, $sourceKeys, $ownerStores);
                hash_update($hash, json_encode([$assignment->resource, $assignment->recordId, $assignment->owner?->toArray(), $canonical, $assignment->legacyTenantId], JSON_THROW_ON_ERROR)."\n");
            }
        }

        return hash_final($hash);
    }

    /**
     * Custom owner policies observe each assignment before the next source read.
     *
     * @param  array<OwnershipMapping>  $assignments
     */
    private function canBatchSourceKeys(array $assignments): bool
    {
        return ! array_any($assignments, static fn (OwnershipMapping $assignment): bool => $assignment->owner !== null)
            || $this->container->get(OwnerAvailability::class) instanceof ExistingOwnerAvailability;
    }

    /**
     * Read exact physical keys from the writer for one bounded set of native mappings.
     *
     * @param  iterable<OwnershipMapping>  $assignments
     * @param  Graph  $graph
     * @return array<string, array<int|string, true>>
     */
    private function sourceKeys(iterable $assignments, array $graph): array
    {
        $requested = [];
        foreach ($assignments as $assignment) {
            $this->assertIdentifiers($assignment);
            if (! isset($graph['owners'][$assignment->resource])) {
                throw new OwnershipConfigurationInvalid('A mapping names a resource outside the selected graph.');
            }
            $requested[$assignment->resource][] = $assignment->recordId;
        }
        $found = [];
        $registry = $this->container->make(OwnershipResourceRegistry::class);
        foreach ($requested as $resource => $recordIds) {
            $definition = $registry->get($resource);
            $source = new $definition->model;
            $key = $source->getKeyName();
            $values = $source->getConnection()->table($source->getTable())->useWritePdo()->whereIn($key, $recordIds)->pluck($key);
            foreach ($values as $value) {
                if (is_int($value) || is_string($value)) {
                    $found[$resource][(string) $value] = true;
                }
            }
        }

        return $found;
    }

    /**
     * Persist one resumable phase boundary.
     *
     * @param  Checkpoints  $checkpoints
     */
    public function checkpoint(string $id, string $status, array $checkpoints): void
    {
        $connection = $this->connections->core();
        $connection->transaction(function () use ($connection, $id, $status, $checkpoints): void {
            $connection->table(Tables::Runs)->where('id', $id)->update(['status' => $status, 'checkpoints' => json_encode($checkpoints, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $connection->table(Tables::Checkpoints)->insert(['id' => (string) Str::uuid(), 'run_id' => $id, 'phase' => $status, 'checkpoint' => json_encode($checkpoints, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        });
    }

    /** Count reviewed physical identities without restoring any package model. */
    public function countForResource(OwnershipAdoptionPlan $plan, string $resource): int
    {
        return $this->connections->core()->table(Tables::Mappings)->where('run_id', $plan->id)->where('resource', $resource)->count();
    }

    /** Resolve one exact reviewed mapping only inside a fenced package phase. */
    public function forRecord(OwnershipAdoptionPlan $plan, string $resource, string $recordId): OwnershipMapping
    {
        if (! $this->scope->active()) {
            throw new OwnershipViolation('Adoption mappings are available only inside a coordinated phase.');
        }
        $row = $this->connections->core()->table(Tables::Mappings)->where('run_id', $plan->id)->where('resource', $resource)->where('record_id', $recordId)->first();
        if ($row === null) {
            throw new OwnershipConfigurationInvalid('Every adopted record requires an explicit canonical mapping.');
        }

        return $this->decodeMapping($row);
    }

    /** Validate persisted untyped database fields before constructing immutable protocol values. */
    private function decodeMapping(\stdClass $row): OwnershipMapping
    {
        if (! is_string($row->resource) || ! is_string($row->record_id) || ! is_string($row->owner_reference) || ! is_string($row->metadata)
            || ($row->legacy_tenant_id !== null && ! is_string($row->legacy_tenant_id))) {
            throw new OwnershipConfigurationInvalid('Invalid persisted ownership mapping.');
        }
        $owner = json_decode($row->owner_reference, true, 32, JSON_THROW_ON_ERROR);
        $decoded = json_decode($row->metadata, true, 32, JSON_THROW_ON_ERROR);
        if (($owner !== null && ! is_array($owner)) || ! is_array($decoded)) {
            throw new OwnershipConfigurationInvalid('Persisted ownership requires scalar JSON maps.');
        }
        $metadata = [];
        foreach ($decoded as $key => $value) {
            if (! is_string($key)) {
                throw new OwnershipConfigurationInvalid('Assignment metadata requires named fields.');
            }
            $metadata[$key] = $value;
        }

        return new OwnershipMapping($row->resource, $row->record_id, $owner === null ? null : OwnerReference::fromArray($owner), $metadata, $row->legacy_tenant_id);
    }

    /**
     * Validate physical bounds, owner availability, and the owning package's optional schema.
     *
     * @param  Graph  $graph
     * @param  array<string, array<int|string, true>>|null  $sourceKeys
     * @param  array<int, string|null>|null  $ownerStores
     */
    private function validateAssignment(string $runId, OwnershipMapping $assignment, array $graph, ?array $sourceKeys = null, ?array &$ownerStores = null): string
    {
        if ($sourceKeys === null) {
            $this->assertIdentifiers($assignment);
        }
        $owner = $graph['owners'][$assignment->resource] ?? throw new OwnershipConfigurationInvalid('A mapping names a resource outside the selected graph.');
        $definition = $this->container->make(OwnershipResourceRegistry::class)->get($assignment->resource);
        $native = null;
        if ($assignment->owner === null) {
            if (! $definition->allowsCentral || $assignment->legacyTenantId !== null) {
                throw new OwnershipConfigurationInvalid('Central disposition requires an explicitly central-capable resource and no legacy tenant.');
            }
        } else {
            $resolver = $this->container->make(OwnerIdentityResolver::class);
            $native = $ownerStores === null ? $resolver->resolve($assignment->owner) : $resolver->resolveWithStoreIdentities($assignment->owner, $ownerStores);
            $this->container->make(OwnerAvailability::class)->assertAvailable($native);
        }
        $adapter = $graph['adapters'][$owner];
        if ($sourceKeys === null) {
            $source = $adapter instanceof OwnershipAdoptionSource ? $adapter->sourceFor($assignment->resource) : new $definition->model;
            $key = $source->getKeyName();
            $persistedKey = $source->getConnection()->table($source->getTable())->useWritePdo()->where($key, $assignment->recordId)->value($key);
            $exists = (is_int($persistedKey) || is_string($persistedKey)) && (string) $persistedKey === $assignment->recordId;
        } else {
            $exists = isset($sourceKeys[$assignment->resource][$assignment->recordId]);
        }
        if (! $exists) {
            throw new OwnershipConfigurationInvalid('A mapping must name the exact existing physical source record.');
        }
        if ($assignment->owner !== null && $definition->ownership->owner !== null && $native::class !== $this->container->make(OwnerRegistry::class)->model($definition->ownership->owner)) {
            throw new OwnershipViolation('The mapping owner differs from the declared concrete owner.');
        }
        $canonical = $this->metadataBytes($assignment);
        if ($adapter instanceof OwnershipAdoptionMetadataValidator) {
            $this->scope->during($runId, $adapter::class, 'metadata', fn () => $adapter->validateAssignment($assignment));
        } elseif ($assignment->metadata !== []) {
            throw new OwnershipConfigurationInvalid('This adoption adapter does not accept metadata.');
        }

        return $canonical;
    }

    private function assertIdentifiers(OwnershipMapping $assignment): void
    {
        foreach ([$assignment->resource, $assignment->recordId] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 191 || str_contains($value, "\0")) {
                throw new OwnershipConfigurationInvalid('Mapping identifiers require 1 to 191 characters.');
            }
        }
        if ($assignment->legacyTenantId !== null && ! Str::isUuid($assignment->legacyTenantId)) {
            throw new OwnershipConfigurationInvalid('Legacy tenant references require their original canonical UUID.');
        }
    }

    /** Canonicalize immutable metadata bytes without granting current assignment authority. */
    private function metadataBytes(OwnershipMapping $assignment): string
    {
        $nodes = 0;
        $canonical = json_encode($this->canonical($assignment->metadata, 0, $nodes), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($canonical) > 16384) {
            throw new OwnershipConfigurationInvalid('Assignment metadata exceeds 16384 bytes.');
        }

        return $canonical;
    }

    /** Canonicalize bounded JSON values without coercing objects or credentials. */
    private function canonical(mixed $value, int $depth, int &$nodes): mixed
    {
        if (++$nodes > 2048 || $depth > 16) {
            throw new OwnershipConfigurationInvalid('Assignment metadata is too complex.');
        }
        if (is_array($value)) {
            if (! array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $item) {
                $value[$key] = $this->canonical($item, $depth + 1, $nodes);
            }

            return $value;
        }
        if ($value === null || is_string($value) || is_bool($value) || is_int($value) || (is_float($value) && is_finite($value))) {
            return $value;
        }
        throw new OwnershipConfigurationInvalid('Assignment metadata must contain JSON values only.');
    }
}
