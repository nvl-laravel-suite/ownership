<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\OwnershipActorResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Binds immutable adoption input to configured classes, morphs and actual stores.
 *
 * @phpstan-type ModelFacts array{class-string<Model>, string, string, string, string, string|null, string}
 * @phpstan-type PrincipalFacts array{string, string, string, string|null, string}
 * @phpstan-type Snapshot array{hash: string, fingerprints: array<string, string>, stores: array<int, string>}
 *
 * @internal
 */
final readonly class OwnershipConfigurationFingerprint
{
    /** Retain only declaration and storage services. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnerRegistry $owners, private OwnershipConnections $connections, private InheritedOwnershipResolver $parents, private OwnershipActorResolver $actors) {}

    /** Hash one exact declaration against fresh operation-local store facts.
     * @param  array<int, string>  $stores
     */
    public function fingerprint(string $resource, array $stores = []): string
    {
        return $this->snapshot([$resource], $stores)['fingerprints'][$resource];
    }

    /** @param list<string> $resources */
    public function hash(array $resources): string
    {
        return $this->snapshot($resources)['hash'];
    }

    /** Capture metadata once for one pass; callers must take a fresh snapshot after callbacks.
     *
     * @param  list<string>  $resources
     * @param  array<int, string>  $stores
     * @return Snapshot
     */
    public function snapshot(array $resources, array $stores = []): array
    {
        sort($resources);
        $fingerprints = [];
        if ($resources !== []) {
            $principals = $this->principals($stores);
            $connection = $this->connections->core();
            $coordinator = [$connection->getName(), $this->store($connection, $stores)];
            $owners = [];
            foreach ($resources as $resource) {
                $fingerprints[$resource] = $this->resourceFingerprint($resource, $stores, $owners, $principals, $coordinator);
            }
        }

        return ['hash' => hash('sha256', json_encode($fingerprints, JSON_THROW_ON_ERROR)), 'fingerprints' => $fingerprints, 'stores' => $stores];
    }

    /** Reuse actual store and owner facts only within this snapshot.
     *
     * @param  array<int, string>  $stores
     * @param  array<string, ModelFacts>  $owners
     * @param  array<string, PrincipalFacts>  $principals
     * @param  array{string|null, string}  $coordinator
     */
    private function resourceFingerprint(string $resource, array &$stores, array &$owners, array $principals, array $coordinator): string
    {
        $definition = $this->resources->get($resource);
        $model = new $definition->model;
        $reference = $definition->ownership->owner;
        $owner = $reference === null ? null : ($owners[$reference] ??= $this->owner($reference, $stores));

        return hash('sha256', json_encode([
            'definition' => $definition,
            'principals' => [$this->actors::class, $principals],
            'relation' => $definition->ownership->strategy === 'inherited' ? $this->parents->fingerprint($definition) : null,
            'model' => $this->model($model, $model->getMorphClass(), $stores),
            'owner' => $owner,
            'coordinator' => $coordinator,
        ], JSON_THROW_ON_ERROR));
    }

    /** Resolve through Core's canonical replacement and morph rules once per distinct reference.
     * @param  array<int, string>  $stores
     * @return ModelFacts
     */
    private function owner(string $reference, array &$stores): array
    {
        $class = $this->owners->model($reference);
        $model = new $class;

        return $this->model($model, $this->owners->aliasFor($model), $stores);
    }

    /** @param array<int, string> $stores
     * @return array<string, PrincipalFacts>
     */
    private function principals(array &$stores): array
    {
        $principals = [];
        foreach ($this->owners->all() as $class) {
            if (is_a($class, Authenticatable::class, true)) {
                $actor = new $class;
                $connection = $actor->getConnection();
                $principals[$class] = [$actor->getTable(), $actor->getKeyName(), $actor->getKeyType(), $connection->getName(), $this->store($connection, $stores)];
            }
        }
        ksort($principals);

        return $principals;
    }

    /** @param array<int, string> $stores
     * @return ModelFacts
     */
    private function model(Model $model, string $morph, array &$stores): array
    {
        $connection = $model->getConnection();

        return [$model::class, $morph, $model->getTable(), $model->getKeyName(), $model->getKeyType(), $connection->getName(), $this->store($connection, $stores)];
    }

    /** @param array<int, string> $stores */
    private function store(Connection $connection, array &$stores): string
    {
        return $stores[spl_object_id($connection)] ??= $this->connections->identity($connection);
    }
}
