<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Delegates owner membership and entry authorization to the host application.
 *
 * @api
 */
interface OwnerAccess
{
    /** Deny an actor unless current host policies admit the canonical owner. */
    public function assertCanEnter(Authenticatable $actor, Model $owner): void;
}
