<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter;

/** Adopts only the already declared root resource. */
final class IncrementalRootAdapter extends FreshHostOwnershipAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['assets.column'];
    }
}
