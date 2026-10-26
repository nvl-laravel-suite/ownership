<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Denies central capabilities without a focused host policy.
 *
 * @internal
 */
final readonly class DenyCentralAccess implements CentralAccess
{
    /** Deny implicit central authority independently from owner membership. */
    public function assertCanRun(string $operation, Authenticatable $actor): void
    {
        throw new OwnershipViolation;
    }
}
