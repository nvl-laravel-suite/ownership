<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Denies privileged owner operations without a focused host system policy. */
final readonly class DenyOwnerSystemAccess implements OwnerSystemAccess
{
    /** Deny implicit or unconfigured system authority. */
    public function assertCanRun(string $operation, Model $owner): void
    {
        throw new OwnershipViolation;
    }
}
