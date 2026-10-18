<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Supplies host lifecycle availability independently from membership or tenant status.
 *
 * @api
 */
interface OwnerAvailability
{
    /** Require the freshly persisted owner to remain available. */
    public function assertAvailable(Model $owner): void;
}
