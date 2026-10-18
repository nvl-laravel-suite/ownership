<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Authorizes explicit bounded system operations on canonical owners.
 *
 * @api
 */
interface OwnerSystemAccess
{
    /** Deny unregistered or unauthorized system work. */
    public function assertCanRun(string $operation, Model $owner): void;
}
