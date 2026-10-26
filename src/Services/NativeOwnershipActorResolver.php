<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Ownership\Contracts\OwnershipActorResolver;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Reloads explicitly registered native host actors on their primary source.
 *
 * @internal
 */
final readonly class NativeOwnershipActorResolver implements OwnershipActorResolver
{
    /** Use the single host model identity registry. */
    public function __construct(private OwnerRegistry $models) {}

    /** Reject removed, lossy, replaced, or undeclared actor identities. */
    public function resolve(string $type, string $id): Authenticatable
    {
        $class = $this->models->model($type);
        $model = new $class;
        if (! $model instanceof Authenticatable || $class !== $type) {
            throw new OwnershipViolation;
        }
        $actor = $model->newQuery()->useWritePdo()->whereKey($id)->first();
        if (! $actor instanceof Authenticatable || $actor::class !== $type) {
            throw new OwnershipViolation;
        }
        $identifier = $actor->getAuthIdentifier();
        if ((! is_string($identifier) && ! is_int($identifier)) || (string) $identifier !== $id) {
            throw new OwnershipViolation;
        }

        return $actor;
    }
}
