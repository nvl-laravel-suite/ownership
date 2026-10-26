<?php

declare(strict_types=1);

namespace Nvl\Ownership\Observers;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Services\InheritedOwnershipResolver;
use Nvl\Ownership\Services\OwnershipCreationScope;
use Nvl\Ownership\Services\OwnershipRecordReader;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\DisabledOwnershipModelGuard;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Validates model persistence through declared dependencies and canonical storage.
 *
 * @internal
 */
final readonly class OwnershipModelObserver implements OwnershipModelGuard
{
    /** Resolve scoped services at each native persistence boundary. */
    public function __construct(private Repository $configuration, private OwnershipResourceRegistry $resources, private OwnershipBoundary $boundary, private OwnershipContext $context, private OwnershipCreationScope $creation, private InheritedOwnershipResolver $parents, private OwnershipRecordReader $records, private DisabledOwnershipModelGuard $disabled) {}

    /** Validate generated creation fields or locked immutable existing ownership. */
    public function saving(Model $model): void
    {
        $registered = false;
        foreach ($this->resources->all() as $definition) {
            if ($definition->model === $model::class) {
                $registered = true;
                break;
            }
        }
        if (! $registered || $this->configuration->get('nvl-ownership.enabled') !== true) {
            $this->disabled->saving($model);

            return;
        }
        $resource = $this->resources->forModel($model);
        $boundary = $this->boundary;
        $this->records->assertModel($model, $resource);
        if ($model->exists || $resource->ownership->strategy === 'unscoped') {
            $boundary->assertRecord($model, $resource->key);

            return;
        }
        $attributes = $boundary->attributes($resource->key);
        $this->creation->claim($model, $attributes);
        if ($resource->ownership->strategy === 'inherited') {
            $reference = $this->parents->reference($model, $resource, true, [$resource->key]);
            if ($reference != $this->context->snapshot()->owner) {
                throw new OwnershipViolation;
            }
        }
    }

    /** Keep deletion under the same source transaction and fresh canonical owner guard. */
    public function deleting(Model $model): void
    {
        if ($this->configuration->get('nvl-ownership.enabled') !== true) {
            $this->disabled->deleting($model);

            return;
        }
        $registered = false;
        foreach ($this->resources->all() as $definition) {
            if ($definition->model === $model::class) {
                $registered = true;
                break;
            }
        }
        if (! $registered) {
            $this->disabled->deleting($model);

            return;
        }
        $resource = $this->resources->forModel($model);
        $this->boundary->assertRecord($model, $resource->key);
    }
}
