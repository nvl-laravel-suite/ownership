<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary as BoundaryContract;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipIdentityFields;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tenancy\Services\PersistedTenantStorage;

/**
 * Intersects package queries and writes with admitted canonical persisted ownership.
 *
 * @internal
 */
final readonly class OwnershipBoundary implements BoundaryContract
{
    /** Resolve current scope services on every entry, including retained workers. */
    public function __construct(private Container $container) {}

    /** @template T of Model
     * @param  Builder<T>  $query
     * @return Builder<T>
     */
    public function query(Builder $query, string $resource): Builder
    {
        $definition = $this->resources()->get($resource);
        $this->container->make(OwnershipRecordReader::class)->assertModel($query->getModel(), $definition);
        if (! $this->enabled()) {
            return $this->container->make(LegacyOwnershipBoundary::class)->query($query, $resource);
        }
        $base = $query->getQuery();
        if ($base->from !== $query->getModel()->getTable() || $base->getConnection() !== $query->getModel()->getConnection() || $base->unions !== null) {
            throw new OwnershipViolation('Ownership queries require their exact canonical source without unions.');
        }
        $snapshot = $this->admit($definition);
        if ($snapshot !== null) {
            $query->useWritePdo();
            $this->scope($query, $definition, $snapshot, []);
        }

        return $query;
    }

    /** Read persisted owner facts and reject forged storage or dirty protected fields. */
    public function assertRecord(Model $record, string $resource): void
    {
        $definition = $this->resources()->get($resource);
        $this->container->make(OwnershipRecordReader::class)->assertModel($record, $definition);
        if (! $this->enabled()) {
            $this->container->make(LegacyOwnershipBoundary::class)->assertRecord($record, $resource);

            return;
        }
        $snapshot = $this->admit($definition);
        if ($snapshot === null) {
            return;
        }
        foreach ($this->protectedFields($definition) as $field) {
            if ($record->isDirty($field)) {
                throw new OwnershipViolation('Primary owner, native identity and canonical parent fields are immutable.');
            }
        }
        $fresh = $this->container->make(OwnershipRecordReader::class)->persisted($record, $definition, $record->getConnection()->transactionLevel() > 0);
        foreach ($this->protectedFields($definition) as $field) {
            if (! $this->samePersistedField($field, $record->getRawOriginal($field), $fresh->getRawOriginal($field))) {
                throw new OwnershipViolation('The model no longer represents the persisted primary ownership facts.');
            }
        }
        $reference = $this->container->make(OwnershipCanonicalResolver::class)->fromPersisted($fresh, $definition, $record->getConnection()->transactionLevel() > 0);
        if ($reference != $snapshot->owner) {
            throw new OwnershipViolation;
        }
    }

    /** @return array<string, int|string|null> */
    public function attributes(string $resource): array
    {
        if (! $this->enabled()) {
            return $this->container->make(LegacyOwnershipBoundary::class)->attributes($resource);
        }
        $definition = $this->resources()->get($resource);
        $snapshot = $this->admit($definition);
        if ($snapshot === null) {
            return [];
        }
        $attributes = ['owner_scope_key' => $snapshot->owner?->scopeKey() ?? 'central'];
        if ($definition->ownership->strategy === 'column') {
            $attributes[$definition->ownership->foreignKey ?? throw new OwnershipConfigurationInvalid] = $snapshot->owner?->key;
        } elseif ($definition->ownership->strategy === 'captured') {
            $attributes['owner_reference'] = $snapshot->owner === null ? null : json_encode($snapshot->owner->toArray(), JSON_THROW_ON_ERROR);
        }

        return $attributes;
    }

    /** Derive cache, lock and storage namespaces only after admission. */
    public function key(string $resource, string $identity): string
    {
        if (! $this->enabled()) {
            return $this->container->make(LegacyOwnershipBoundary::class)->key($resource, $identity);
        }
        $snapshot = $this->admit($this->resources()->get($resource));

        return $snapshot === null ? $identity : 'nvl:ownership:'.hash('sha256', json_encode([$resource, $snapshot->owner?->toArray(), $snapshot->generations, $identity], JSON_THROW_ON_ERROR));
    }

    /** @return list<string> */
    public function protectedFields(OwnershipResourceDefinition $resource): array
    {
        return $this->container->make(OwnershipIdentityFields::class)->forResource($resource);
    }

    /** Preserve native scalar bytes and complete JSON identity across driver serialization. */
    private function samePersistedField(string $field, mixed $original, mixed $persisted): bool
    {
        if ($original === $persisted) {
            return true;
        }
        if ($field === 'owner_reference' && is_string($original) && is_string($persisted)) {
            $before = json_decode($original, true, 16, JSON_THROW_ON_ERROR);
            $after = json_decode($persisted, true, 16, JSON_THROW_ON_ERROR);

            return is_array($before) && is_array($after) && OwnerReference::fromArray($before) == OwnerReference::fromArray($after);
        }

        return (is_int($original) || is_string($original)) && (is_int($persisted) || is_string($persisted)) && (string) $original === (string) $persisted;
    }

    /** Revalidate status and generation before exposing any owned source. */
    private function admit(OwnershipResourceDefinition $resource): ?OwnershipSnapshot
    {
        $this->container->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
        $public = $this->container->make(OwnershipContext::class)->snapshot()->publicSite;
        if ($public !== null && ! in_array($resource->key, $public->resources, true)) {
            throw new OwnershipViolation('The resource is outside the admitted public site graph.');
        }
        if ($resource->ownership->strategy === 'unscoped') {
            $this->container->make(PersistedTenantStorage::class)->assertUnadopted((new $resource->model)->getConnection(), $resource->key);

            return null;
        }
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        $snapshot = $this->container->make(OwnershipContext::class)->snapshot();
        if (! in_array($snapshot->mode, [OwnershipContextMode::Owner, OwnershipContextMode::Central], true)
            || ($snapshot->mode === OwnershipContextMode::Central && ! $resource->allowsCentral)) {
            throw new OwnershipViolation;
        }
        $this->container->make(OwnershipInstallationState::class)->assertSnapshot($snapshot);
        $this->container->make(OwnershipSchema::class)->assertReady($resource->key);
        if ($snapshot->owner !== null) {
            $canonical = $this->container->make(OwnerIdentityResolver::class)->resolve($snapshot->owner);
            $this->container->make(OwnerAvailability::class)->assertAvailable($canonical);
            if ($resource->ownership->owner !== null && $canonical::class !== $this->container->make(OwnerRegistry::class)->model($resource->ownership->owner)) {
                throw new OwnershipViolation;
            }
        }

        return $snapshot;
    }

    /** @template T of Model
     * @param  Builder<T>  $query
     * @param  list<string>  $visited
     */
    private function scope(Builder $query, OwnershipResourceDefinition $resource, OwnershipSnapshot $snapshot, array $visited): void
    {
        if (in_array($resource->key, $visited, true)) {
            throw new OwnershipViolation;
        }
        $visited[] = $resource->key;
        $base = $query->getQuery();
        if ($base->wheres !== []) {
            $nested = $base->forNestedWhere();
            $nested->wheres = $base->wheres;
            $nested->setBindings($base->getRawBindings()['where'], 'where');
            $base->wheres = [];
            $base->setBindings([], 'where');
            $base->addNestedWhereQuery($nested);
        }
        $model = $query->getModel();
        $query->where($model->qualifyColumn('owner_scope_key'), $snapshot->owner?->scopeKey() ?? 'central');
        $definition = $resource->ownership;
        if ($definition->strategy === 'column') {
            $query->where($model->qualifyColumn($definition->foreignKey ?? throw new OwnershipConfigurationInvalid), $snapshot->owner?->key);
        } elseif ($definition->strategy === 'pivot') {
            if ($snapshot->owner === null) {
                $query->whereNotExists(function (QueryBuilder $pivot) use ($definition, $model): void {
                    $table = $definition->table ?? throw new OwnershipConfigurationInvalid;
                    $pivot->from($table)->selectRaw('1')->whereColumn($table.'.'.$definition->resourceKey, $model->qualifyColumn($model->getKeyName()));
                });

                return;
            }
            $query->whereExists(function (QueryBuilder $pivot) use ($definition, $model, $snapshot): void {
                $table = $definition->table ?? throw new OwnershipConfigurationInvalid;
                $pivot->from($table)->selectRaw('1')->whereColumn($table.'.'.$definition->resourceKey, $model->qualifyColumn($model->getKeyName()))
                    ->where($table.'.'.$definition->ownerKey, $snapshot->owner->key)->where($table.'.owner_scope_key', $snapshot->owner->scopeKey());
            });
        } elseif ($definition->strategy === 'captured') {
            if ($snapshot->owner === null) {
                $query->whereNull($model->qualifyColumn('owner_reference'));
            } else {
                $json = json_encode($snapshot->owner->toArray(), JSON_THROW_ON_ERROR);
                match ($model->getConnection()->getDriverName()) {
                    'pgsql' => $query->whereRaw('owner_reference::jsonb = ?::jsonb', [$json]),
                    'mysql', 'mariadb' => $query->whereRaw('JSON_CONTAINS(owner_reference, ?) AND JSON_LENGTH(owner_reference) = 5', [$json]),
                    default => $query->whereRaw('json(owner_reference) = json(?)', [$json]),
                };
            }
        } elseif ($definition->strategy === 'inherited') {
            $parents = $this->container->make(InheritedOwnershipResolver::class);
            $relation = $parents->relation($resource);
            $types = $relation instanceof MorphTo ? $parents->types($resource) : ['' => $this->resources()->get($resource->parents()[0])];
            $query->where(function (Builder $alternatives) use ($types, $relation, $model, $snapshot, $visited): void {
                foreach ($types as $type => $parent) {
                    $alternatives->orWhere(function (Builder $branch) use ($type, $parent, $relation, $model, $snapshot, $visited): void {
                        $parentModel = new $parent->model;
                        $driver = $model->getConnection()->getDriverName();
                        $parentQuery = $parentModel->newQueryWithoutScopes()->where(new OwnershipTextColumn($parentModel->qualifyColumn($parentModel->getKeyName()), $driver), '=', new OwnershipTextColumn($model->qualifyColumn($relation->getForeignKeyName()), $driver));
                        if ($relation instanceof MorphTo) {
                            $branch->where($model->qualifyColumn($relation->getMorphType()), $type);
                        }
                        $this->scope($parentQuery, $parent, $snapshot, $visited);
                        $branch->whereExists($parentQuery->toBase());
                    });
                }
            });
        }
    }

    /** Return immutable resource metadata. */
    private function resources(): OwnershipResourceRegistry
    {
        return $this->container->make(OwnershipResourceRegistry::class);
    }

    /** Read the current deployment authority without retaining request state. */
    private function enabled(): bool
    {
        return $this->container->make(Repository::class)->get('nvl-ownership.enabled') === true;
    }
}
