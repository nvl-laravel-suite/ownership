<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence as PersistenceContract;
use Nvl\Support\Ownership\Services\LegacyOwnershipModelPersistence;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Holds canonical locks through all native model events and the actual SQL write. @internal */
final readonly class OwnershipModelPersistence implements PersistenceContract
{
    /** Resolve current guards rather than retaining scoped admission. */
    public function __construct(private Container $container) {}

    /** @param Closure(): bool $persist */
    public function save(Model $model, Closure $persist): bool
    {
        if (! $this->owned($model)) {
            return $this->container->make(LegacyOwnershipModelPersistence::class)->save($model, $persist);
        }

        return $model->getConnection()->transaction(function () use ($model, $persist): bool {
            return $this->container->make(OwnershipWriteLease::class)->during($model->getConnection(), function () use ($model, $persist): bool {
                $this->container->make(OwnershipModelGuard::class)->saving($model);

                return $persist();
            });
        });
    }

    /** @param Closure(): (bool|null) $persist */
    public function delete(Model $model, Closure $persist): ?bool
    {
        if (! $this->owned($model)) {
            return $this->container->make(LegacyOwnershipModelPersistence::class)->delete($model, $persist);
        }

        return $model->getConnection()->transaction(function () use ($model, $persist): ?bool {
            return $this->container->make(OwnershipWriteLease::class)->during($model->getConnection(), function () use ($model, $persist): ?bool {
                $this->container->make(OwnershipModelGuard::class)->deleting($model);

                return $persist();
            });
        });
    }

    /** Preserve native unowned persistence without retaining any model or scope. */
    private function owned(Model $model): bool
    {
        if ($this->container->make(Repository::class)->get('nvl-ownership.enabled') !== true) {
            return false;
        }
        foreach ($this->container->make(OwnershipResourceRegistry::class)->all() as $resource) {
            if ($resource->model === $model::class) {
                return $resource->ownership->strategy !== 'unscoped';
            }
        }

        return false;
    }
}
