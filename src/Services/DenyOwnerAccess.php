<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Supplies default-denying entry policies without an Auth dependency. */
final readonly class DenyOwnerAccess implements OwnerAccess
{
    /** Deny owner entry without an explicit host policy. */
    public function assertCanEnter(Authenticatable $actor, Model $owner): void
    {
        throw new OwnershipViolation;
    }
}
