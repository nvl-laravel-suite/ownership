<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Illuminate\Database\Eloquent\Model;

/** Supplies a fixed historical physical source when native adoption creates a separate target. @api */
interface OwnershipAdoptionSource
{
    /** Resolve the package-declared source without trusting mapping metadata as a table locator. */
    public function sourceFor(string $resource): Model;
}
