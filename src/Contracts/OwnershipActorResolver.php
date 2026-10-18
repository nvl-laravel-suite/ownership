<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/** Resolves a fresh canonical actor without depending on NVL Auth. @api */
interface OwnershipActorResolver
{
    /** Reload existence and native identity before the host access policy runs. */
    public function resolve(string $type, string $id): Authenticatable;
}
