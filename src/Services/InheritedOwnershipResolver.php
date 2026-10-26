<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Reads the package-approved persisted parent independently of loaded relations.
 *
 * @internal
 */
final readonly class InheritedOwnershipResolver
{
    /** Resolve graph recursion without retaining a scoped owner or parent. */
    public function __construct(private Container $container, private OwnershipResourceRegistry $resources) {}

    /** @return BelongsTo<Model, Model> */
    public function relation(OwnershipResourceDefinition $resource): BelongsTo
    {
        $model = new $resource->model;
        $name = $resource->ownership->relation ?? throw new OwnershipConfigurationInvalid;
        $relation = $model->{$name}();
        if (! $relation instanceof BelongsTo || $resource->parents() === []) {
            throw new OwnershipConfigurationInvalid('Inherited ownership requires a declared native parent relationship.');
        }
        if (! $relation instanceof MorphTo && count($resource->parents()) !== 1) {
            throw new OwnershipConfigurationInvalid('A concrete relationship requires one canonical parent.');
        }
        foreach ($resource->parents() as $key) {
            $parent = $this->resources->get($key);
            $canonical = new $parent->model;
            if ($canonical->getConnection() !== $model->getConnection()
                || ((! $relation instanceof MorphTo || $relation->getQualifiedOwnerKeyName() !== '') && $relation->getOwnerKeyName() !== $canonical->getKeyName())
                || (! $relation instanceof MorphTo && ($relation->getRelated()::class !== $parent->model || $relation->getRelated()->getTable() !== $canonical->getTable()))) {
                throw new OwnershipConfigurationInvalid('The inherited relation differs from its canonical parent policy.');
            }
        }

        return $relation;
    }

    /** Filter native Laravel identities to the finite package allowlist.
     * @return array<string, OwnershipResourceDefinition>
     */
    public function types(OwnershipResourceDefinition $resource): array
    {
        $types = [];
        foreach ($resource->parents() as $key) {
            $parent = $this->resources->get($key);
            $types[$parent->model] = $parent;
            foreach (Relation::morphMap() as $alias => $class) {
                if ($class === $parent->model) {
                    $types[(string) $alias] = $parent;
                }
            }
        }

        return $types;
    }

    /** Structural relation metadata belongs to the immutable adoption fingerprint.
     * @return array{class: class-string, foreign: string, owner: string|null, morph: string|null, types: array<string, string>}
     */
    public function fingerprint(OwnershipResourceDefinition $resource): array
    {
        $relation = $this->relation($resource);
        $types = [];
        foreach ($this->types($resource) as $type => $parent) {
            $types[$type] = $parent->key;
        }
        ksort($types);

        return ['class' => $relation::class, 'foreign' => $relation->getForeignKeyName(), 'owner' => $relation instanceof MorphTo && $relation->getQualifiedOwnerKeyName() === '' ? null : $relation->getOwnerKeyName(), 'morph' => $relation instanceof MorphTo ? $relation->getMorphType() : null, 'types' => $types];
    }

    /** @param list<string> $visited */
    public function reference(Model $record, OwnershipResourceDefinition $resource, bool $lock, array $visited): ?OwnerReference
    {
        $relation = $this->relation($resource);
        if ($relation instanceof MorphTo) {
            $type = $record->getAttribute($relation->getMorphType());
            $parent = is_string($type) ? ($this->types($resource)[$type] ?? null) : null;
            if ($parent === null) {
                throw new OwnershipViolation;
            }
        } else {
            $parent = $this->resources->get($resource->parents()[0]);
        }
        $model = new $parent->model;
        $key = $record->getAttribute($relation->getForeignKeyName());
        if (! is_int($key) && ! is_string($key)) {
            throw new OwnershipViolation;
        }
        $row = $model->getConnection()->table($model->getTable())->useWritePdo()->where($model->getKeyName(), $key)->first();
        $field = $model->getKeyName();
        if ($row === null || (! is_int($row->{$field}) && ! is_string($row->{$field})) || (string) $row->{$field} !== (string) $key) {
            throw new OwnershipViolation;
        }

        return $this->container->make(OwnershipCanonicalResolver::class)->reference($this->container->make(OwnershipRecordReader::class)->hydrate($model, $row), $parent, $lock, $visited);
    }
}
