<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Root with a native string owner FK rather than an invented UUID owner. */
final class StringAsset extends ColumnAsset
{
    protected $table = 'ownership_test_string_assets';

    /** Preserve the host's exact native string key type. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(StringCompany::class, 'company_key');
    }
}
