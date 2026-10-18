<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Keeps central capabilities separate from ordinary owner membership.
 *
 * @api
 */
interface CentralAccess
{
    /** Require host authorization for one bounded central operation. */
    public function assertCanRun(string $operation, Authenticatable $actor): void;
}
