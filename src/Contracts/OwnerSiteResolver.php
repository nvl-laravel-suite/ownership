<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Illuminate\Http\Request;
use Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext;

/**
 * Host-owned public site registry. Resolve only persisted, enabled host mappings.
 * Never derive owner, site or canonical origin from caller-selected identity inputs.
 *
 * @api
 */
interface OwnerSiteResolver
{
    /** Resolve an exact admitted request origin, or deny unknown/disabled sites. */
    public function resolve(Request $request): ?VerifiedOwnerSiteContext;
}
