<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Atomically persists a configured resource and its immutable primary assignment.
 *
 * @api
 */
final readonly class OwnershipWriter
{
    /** Resolve workflow-scoped permits and admission at each invocation. */
    public function __construct(private Container $container) {}

    /**
     * Join the actual source transaction and verify canonical ownership before its commit.
     *
     * @template T of Model
     *
     * @param  class-string<T>  $modelClass
     * @param  Closure(OwnershipAssignment): T  $persist
     * @return T
     */
    public function create(string $modelClass, Closure $persist): Model
    {
        $model = new $modelClass;
        $resource = $this->container->make(OwnershipResourceRegistry::class)->forModel($model);
        $boundary = $this->container->make(OwnershipBoundary::class);
        $attributes = $boundary->attributes($resource->key);
        if ($resource->ownership->strategy === 'unscoped') {
            return $model->getConnection()->transaction(function () use ($persist, $attributes, $boundary, $resource): Model {
                $record = $persist(new OwnershipAssignment($attributes));
                if (! $record->exists) {
                    throw new OwnershipViolation('The writer must return a persisted concrete record.');
                }
                $boundary->assertRecord($record, $resource->key);

                return $record;
            });
        }

        return $model->getConnection()->transaction(function () use ($modelClass, $persist, $model, $resource, $boundary, $attributes): Model {
            return $this->container->make(OwnershipWriteLease::class)->during($model->getConnection(), function () use ($modelClass, $persist, $model, $resource, $boundary, $attributes): Model {
                $record = $this->container->make(OwnershipCreationScope::class)->during($modelClass, $attributes, fn (): Model => $persist(new OwnershipAssignment($attributes)));
                $this->container->make(OwnershipRecordReader::class)->persisted($record, $resource, true);
                $ownership = $resource->ownership;
                $owner = $this->container->make(OwnershipContext::class)->snapshot()->owner;
                if ($ownership->strategy === 'pivot' && $owner !== null) {
                    $model->getConnection()->table($ownership->table ?? throw new OwnershipConfigurationInvalid)->insert([
                        $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid => $record->getRawOriginal($record->getKeyName()),
                        $ownership->ownerKey ?? throw new OwnershipConfigurationInvalid => $owner->key,
                        'owner_scope_key' => $attributes['owner_scope_key'],
                    ]);
                }
                $boundary->assertRecord($record, $resource->key);

                return $record;
            });
        });
    }
}
