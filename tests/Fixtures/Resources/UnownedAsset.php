<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Ordinary package subclass that deliberately has no ownership schema. */
final class UnownedAsset extends ColumnAsset
{
    protected $table = 'ownership_test_unowned_assets';

    /** Preserve ordinary unowned persistence. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::unscoped();
    }
}
