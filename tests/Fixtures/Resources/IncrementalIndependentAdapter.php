<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter;

/** Adopts an independent resource added by a later deployment. */
final class IncrementalIndependentAdapter extends FreshHostOwnershipAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['assets.string-key'];
    }
}
