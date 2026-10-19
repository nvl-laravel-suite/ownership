<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Container\Container;
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
 *
 * @phpstan-type Checkpoint array{prepared: bool, cursor: string|null, done: bool, activated: bool}
 * @phpstan-type Checkpoints array{manifest: string, adapters: array<string, Checkpoint>, fingerprints: array<string, string>}
 * @phpstan-type Run array{plan: OwnershipAdoptionPlan, status: string, packages: list<string>, checkpoints: Checkpoints}
 */
final readonly class OwnershipAdoptionStore
{
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

        return ['plan' => new OwnershipAdoptionPlan($id, $this->connections->name($this->connections->core()->getName()), $row->mapping_hash, $row->configuration_hash), 'status' => $row->status, 'packages' => array_values(array_filter($packages, 'is_string')), 'checkpoints' => ['manifest' => $checkpoints['manifest'], 'adapters' => $adapters, 'fingerprints' => $fingerprints]];
    }

    /**
     * Stream reviewed rows into indexed storage inside the caller's preparation transaction.
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

        return $this->mappingHash($runId, $graph);
    }

    /**
     * Recompute sorted immutable mapping bytes and revalidate current owner/metadata admission.
     *
     * @param  Graph  $graph
     */
    public function mappingHash(string $runId, array $graph): string
    {
        $connection = $this->connections->core();
        $ordering = match ($connection->getDriverName()) {
            'mysql', 'mariadb' => 'BINARY resource, BINARY record_id',
            'pgsql' => 'resource COLLATE "C", record_id COLLATE "C"',
            default => 'resource COLLATE BINARY, record_id COLLATE BINARY',
        };
        $hash = hash_init('sha256');
        foreach ($connection->table(Tables::Mappings)->where('run_id', $runId)->orderByRaw($ordering)->cursor() as $row) {
            $assignment = $this->decodeMapping($row);
            $canonical = $this->validateAssignment($runId, $assignment, $graph);
            hash_update($hash, json_encode([$assignment->resource, $assignment->recordId, $assignment->owner?->toArray(), $canonical, $assignment->legacyTenantId], JSON_THROW_ON_ERROR)."\n");
        }

        return hash_final($hash);
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
     */
    private function validateAssignment(string $runId, OwnershipMapping $assignment, array $graph): string
    {
        foreach ([$assignment->resource, $assignment->recordId] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 191 || str_contains($value, "\0")) {
                throw new OwnershipConfigurationInvalid('Mapping identifiers require 1 to 191 characters.');
            }
        }
        if ($assignment->legacyTenantId !== null && ! Str::isUuid($assignment->legacyTenantId)) {
            throw new OwnershipConfigurationInvalid('Legacy tenant references require their original canonical UUID.');
        }
        $owner = $graph['owners'][$assignment->resource] ?? throw new OwnershipConfigurationInvalid('A mapping names a resource outside the selected graph.');
        $definition = $this->container->make(OwnershipResourceRegistry::class)->get($assignment->resource);
        $native = null;
        if ($assignment->owner === null) {
            if (! $definition->allowsCentral || $assignment->legacyTenantId !== null) {
                throw new OwnershipConfigurationInvalid('Central disposition requires an explicitly central-capable resource and no legacy tenant.');
            }
        } else {
            $native = $this->container->make(OwnerIdentityResolver::class)->resolve($assignment->owner);
            $this->container->make(OwnerAvailability::class)->assertAvailable($native);
        }
        $adapter = $graph['adapters'][$owner];
        $source = $adapter instanceof OwnershipAdoptionSource ? $adapter->sourceFor($assignment->resource) : new $definition->model;
        $persistedKey = $source->getConnection()->table($source->getTable())->useWritePdo()->where($source->getKeyName(), $assignment->recordId)->value($source->getKeyName());
        if ((! is_int($persistedKey) && ! is_string($persistedKey)) || (string) $persistedKey !== $assignment->recordId) {
            throw new OwnershipConfigurationInvalid('A mapping must name the exact existing physical source record.');
        }
        if ($assignment->owner !== null && $definition->ownership->owner !== null && $native::class !== $this->container->make(OwnerRegistry::class)->model($definition->ownership->owner)) {
            throw new OwnershipViolation('The mapping owner differs from the declared concrete owner.');
        }
        $nodes = 0;
        $canonical = json_encode($this->canonical($assignment->metadata, 0, $nodes), JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION);
        if (strlen($canonical) > 16384) {
            throw new OwnershipConfigurationInvalid('Assignment metadata exceeds 16384 bytes.');
        }
        $adapter = $graph['adapters'][$owner];
        if ($adapter instanceof OwnershipAdoptionMetadataValidator) {
            $this->scope->during($runId, $adapter::class, 'metadata', fn () => $adapter->validateAssignment($assignment));
        } elseif ($assignment->metadata !== []) {
            throw new OwnershipConfigurationInvalid('This adoption adapter does not accept metadata.');
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
