<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter;

/** Exercises a host adapter containing only its declared resources. */
final class FreshAssetsAdapter extends FreshHostOwnershipAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['assets.column', 'assets.child'];
    }
}
