<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Retains one attribute-less canonical model per resource for metadata reads.
 *
 * Table, connection, key and morph facts are read from these instances on every call;
 * no configuration, storage or authorization fact is cached. Callers must not mutate them.
 *
 * @internal
 */
final class OwnershipCanonicalModels
{
    /** @var array<string, Model> */
    private array $models = [];

    /** Return the blank canonical instance of the exact configured resource model. */
    public function for(OwnershipResourceDefinition $resource): Model
    {
        $model = $this->models[$resource->key] ?? null;
        if (! $model instanceof Model || $model::class !== $resource->model) {
            $model = $this->models[$resource->key] = new $resource->model;
        }

        return $model;
    }
}
