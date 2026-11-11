<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter;

/** Adopts host resources stored outside the connection's default schema. */
final class SchemaQualifiedAssetsAdapter extends FreshHostOwnershipAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['tenant.documents', 'tenant.lines'];
    }
}
