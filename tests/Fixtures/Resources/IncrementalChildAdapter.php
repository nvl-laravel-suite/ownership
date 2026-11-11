<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter;

/** Adopts a child, added by a later deployment, of the already active root. */
final class IncrementalChildAdapter extends FreshHostOwnershipAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['assets.child'];
    }
}
