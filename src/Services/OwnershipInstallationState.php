<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Rechecks source stamps against the committed coordinator generation. @internal */
final readonly class OwnershipInstallationState
{
    /** Retain no persisted state or negative readiness cache. */
    public function __construct(private OwnershipConnections $connections, private OwnershipResourceRegistry $resources, private OwnershipConfigurationFingerprint $fingerprints) {}

    /**
     * Capture the full active graph, rejecting partial stamps and immutable drift.
     *
     * @return array<string, string>
     */
    public function generations(): array
    {
        $owned = array_filter($this->resources->all(), static fn ($resource): bool => $resource->ownership->strategy !== 'unscoped');
        if ($owned === []) {
            return [];
        }
        $generations = [];
        $coordinator = $this->connections->core();
        $stores = [];
        foreach ($this->connections->sources() as $id => $source) {
            $stores[$id] = $this->connections->identity($source);
        }
        $fingerprints = [];
        foreach ($owned as $resource) {
            $fingerprints[$resource->key] = $this->fingerprints->fingerprint($resource->key, $stores);
        }
        $markers = [];
        $verified = [];
        foreach ($owned as $resource) {
            $source = $this->connections->resource($resource->key);
            $sourceId = spl_object_id($source);
            $store = $stores[$sourceId];
            if (! isset($markers[$sourceId])) {
                $markers[$sourceId] = [];
                foreach ($source->table(Tables::Markers)->useWritePdo()->get() as $marker) {
                    if (! is_string($marker->resource)) {
                        throw new OwnershipSchemaNotReady('The source marker has an invalid resource identity.');
                    }
                    $markers[$sourceId][$marker->resource] = $marker;
                }
            }
            $row = $markers[$sourceId][$resource->key] ?? null;
            if ($row === null || ! is_string($row->generation) || $row->state !== 'active' || ! in_array($row->schema_version, [1, '1'], true)
                || $row->physical_table !== (new $resource->model)->getTable()
                || $row->source_store !== $store || $row->coordinator_store !== $stores[spl_object_id($coordinator)]
                || $row->configuration_hash !== $fingerprints[$resource->key]) {
                throw new OwnershipSchemaNotReady('The actual source has no matching active ownership generation.');
            }
            if (! isset($verified[$row->generation])) {
                $run = $coordinator->table(Tables::Runs)->useWritePdo()->where('id', $row->generation)->first();
                if ($run === null || $run->status !== 'active' || ! is_string($run->checkpoints) || ! is_string($run->configuration_hash)) {
                    throw new OwnershipSchemaNotReady('The coordinator has not committed this source generation.');
                }
                $checkpoint = json_decode($run->checkpoints, true, 32, JSON_THROW_ON_ERROR);
                if (! is_array($checkpoint) || ! is_array($checkpoint['fingerprints'] ?? null)) {
                    throw new OwnershipSchemaNotReady('The committed generation has no immutable graph.');
                }
                $graph = [];
                foreach ($checkpoint['fingerprints'] as $key => $fingerprint) {
                    if (! is_string($key) || ! is_string($fingerprint) || ($fingerprints[$key] ?? null) !== $fingerprint) {
                        throw new OwnershipSchemaNotReady('The committed resource graph changed.');
                    }
                    $graph[$key] = $fingerprint;
                }
                ksort($graph, SORT_STRING);
                if (hash('sha256', json_encode($graph, JSON_THROW_ON_ERROR)) !== $run->configuration_hash) {
                    throw new OwnershipSchemaNotReady('The committed generation has inconsistent immutable input.');
                }
                $verified[$row->generation] = $graph;
            }
            if (($verified[$row->generation][$resource->key] ?? null) !== $row->configuration_hash) {
                throw new OwnershipSchemaNotReady('The source is outside its committed adoption graph.');
            }
            $generations[$resource->key] = $store.':'.$row->generation;
        }
        ksort($generations, SORT_STRING);

        return $generations;
    }

    /** Reject generation changes after admission, including connections reconfigured in place. */
    public function assertSnapshot(OwnershipSnapshot $snapshot): void
    {
        if ($snapshot->generations !== $this->generations()) {
            throw new OwnershipSchemaNotReady('The admitted installation generation is stale.');
        }
    }
}
