<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Admits fresh existing owners while host lifecycle rules remain replaceable.
 *
 * @internal
 */
final readonly class ExistingOwnerAvailability implements OwnerAvailability
{
    /** Reject missing and soft-deleted canonical owners. */
    public function assertAvailable(Model $owner): void
    {
        if (! $owner->exists || $owner->getKey() === null
            || (method_exists($owner, 'trashed') && $owner->trashed())) {
            throw new OwnershipViolation;
        }
    }
}
