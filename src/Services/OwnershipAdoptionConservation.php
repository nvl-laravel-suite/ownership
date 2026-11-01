<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Contracts\OwnershipAdoptionSource;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use stdClass;

/**
 * Independently compares every physical cutover record with its reviewed canonical owner.
 *
 * @internal
 *
 * @phpstan-type ResourceSnapshot array{store: string, table: string, key: string, rows: int, digest: string}
 * @phpstan-type Snapshot array<string, ResourceSnapshot>
 */
final readonly class OwnershipAdoptionConservation
{
    /** Retain immutable reviewed mappings and exact canonical source readers. */
    public function __construct(private OwnershipAdoptionStore $mappings, private OwnershipResourceRegistry $resources, private OwnershipRecordReader $records, private OwnershipCanonicalResolver $canonical, private OwnershipConnections $connections) {}

    /**
     * Validate current canonical ownership and hash all raw history without retaining payloads.
     *
     * @return Snapshot
     */
    public function snapshot(OwnershipAdoptionAdapter $adapter): array
    {
        $snapshot = [];
        foreach ($adapter->resources() as $key) {
            $definition = $this->resources->get($key);
            $model = new $definition->model;
            $connection = $model->getConnection();
            $digest = hash_init('sha256');
            $count = 0;
            foreach ($connection->table($model->getTable())->useWritePdo()->lazyById(250, $model->getKeyName()) as $row) {
                $this->canonical->fromPersisted($this->records->hydrate($model, $row), $definition);
                $bytes = $this->rowBytes($row);
                hash_update($digest, strlen($bytes).':'.$bytes);
                $count++;
            }
            $snapshot[$key] = ['store' => $this->connections->identity($connection), 'table' => $model->getTable(), 'key' => $model->getKeyName(), 'rows' => $count, 'digest' => hash_final($digest)];
        }

        return $snapshot;
    }

    /** Frame raw scalar values, hashing binary streams before their cursor row is released. */
    private function rowBytes(stdClass $row): string
    {
        $attributes = get_object_vars($row);
        ksort($attributes, SORT_STRING);
        foreach ($attributes as $name => $value) {
            if (is_resource($value)) {
                if (get_resource_type($value) !== 'stream') {
                    throw new OwnershipConfigurationInvalid('Constraint refresh requires a binary resource stream.');
                }
                $position = ftell($value);
                $digest = hash_init('sha256');
                if ($position === false || fseek($value, 0) !== 0) {
                    throw new OwnershipConfigurationInvalid('Constraint refresh could not read a binary resource value.');
                }
                hash_update_stream($digest, $value);
                if (fseek($value, $position) !== 0) {
                    throw new OwnershipConfigurationInvalid('Constraint refresh could not restore a binary resource stream.');
                }
                $attributes[$name] = ['binary_stream_digest' => hash_final($digest)];
            } elseif ($value !== null && ! is_scalar($value)) {
                throw new OwnershipConfigurationInvalid('Constraint refresh requires scalar database values.');
            }
        }

        return serialize($attributes);
    }

    /** Compare all rows, including historical and soft-deleted records, in both directions. */
    public function verify(OwnershipAdoptionPlan $plan, OwnershipAdoptionAdapter $adapter): OwnershipVerification
    {
        $errors = [];
        $reports = [];
        foreach ($adapter->resources() as $key) {
            $definition = $this->resources->get($key);
            $model = new $definition->model;
            $source = $adapter instanceof OwnershipAdoptionSource ? $adapter->sourceFor($key) : $model;
            $connection = $model->getConnection();
            $mapped = $this->mappings->countForResource($plan, $key);
            $persisted = 0;
            $owners = [];
            foreach ($connection->table($model->getTable())->useWritePdo()->orderBy($model->getKeyName())->cursor() as $row) {
                $persisted++;
                $id = $row->{$model->getKeyName()};
                if (! is_int($id) && ! is_string($id)) {
                    $errors[$key.':invalid_record_key'] = true;

                    continue;
                }
                try {
                    $mapping = $this->mappings->forRecord($plan, $key, (string) $id);
                    $reference = $this->canonical->fromPersisted($this->records->hydrate($model, $row), $definition);
                    $scope = $reference?->scopeKey() ?? 'central';
                    $owners[$scope] = ($owners[$scope] ?? 0) + 1;
                    if ($reference != $mapping->owner) {
                        $errors[$key.':reviewed_owner_mismatch'] = true;
                    }
                    if (isset($row->tenant_id) && $row->tenant_id !== $mapping->legacyTenantId) {
                        $errors[$key.':legacy_identity_mismatch'] = true;
                    }
                } catch (OwnershipConfigurationInvalid|OwnershipSchemaNotReady|OwnershipViolation $exception) {
                    $errors[$key.':invalid_or_unmapped_record'] = true;
                }
            }
            $sourceCount = $source->getConnection()->table($source->getTable())->useWritePdo()->count();
            if ($mapped !== $persisted || $sourceCount !== $persisted) {
                $errors[$key.':record_conservation_mismatch'] = true;
            }
            ksort($owners, SORT_STRING);
            $reports[$key] = ['source_store' => $this->connections->identity($connection), 'source_table' => $source->getTable(), 'target_table' => $model->getTable(), 'source' => $sourceCount, 'persisted' => $persisted, 'mapped' => $mapped, 'owners' => $owners];
        }

        return new OwnershipVerification(array_keys($errors), $reports);
    }
}
