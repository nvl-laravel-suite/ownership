<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Connection;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Binds immutable adoption input to configured classes, morphs and actual stores. @internal */
final readonly class OwnershipConfigurationFingerprint
{
    /** Retain only declaration and storage services. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnerRegistry $owners, private OwnershipConnections $connections, private InheritedOwnershipResolver $parents) {}

    /** Hash one exact declaration against fresh operation-local store facts.
     * @param  array<int, string>  $stores
     */
    public function fingerprint(string $resource, array $stores = []): string
    {
        $definition = $this->resources->get($resource);
        $model = new $definition->model;
        $owner = $definition->ownership->owner;
        $owner = $owner === null ? null : $this->owners->model($owner);
        $native = $owner === null ? null : new $owner;

        return hash('sha256', json_encode([
            'definition' => $definition,
            'relation' => $definition->ownership->strategy === 'inherited' ? $this->parents->fingerprint($definition) : null,
            'model' => [$model::class, $model->getMorphClass(), $model->getTable(), $model->getKeyName(), $model->getKeyType(), $model->getConnection()->getName(), $this->store($model->getConnection(), $stores)],
            'owner' => $native === null ? null : [$native::class, $this->owners->aliasFor($native), $native->getTable(), $native->getKeyName(), $native->getKeyType(), $native->getConnection()->getName(), $this->store($native->getConnection(), $stores)],
            'coordinator' => [$this->connections->core()->getName(), $this->store($this->connections->core(), $stores)],
        ], JSON_THROW_ON_ERROR));
    }

    /** @param list<string> $resources */
    public function hash(array $resources): string
    {
        sort($resources);
        $fingerprints = [];
        foreach ($resources as $resource) {
            $fingerprints[$resource] = $this->fingerprint($resource);
        }

        return hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR));
    }

    /** @param array<int, string> $stores */
    private function store(Connection $connection, array $stores): string
    {
        return $stores[spl_object_id($connection)] ?? $this->connections->identity($connection);
    }
}
