<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Operational fact remains owned after its optional business subject disappears. */
final class CapturedOperation extends ColumnAsset
{
    protected $table = 'ownership_test_captured_operations';

    /** Capture the full admitted owner without a consumer subclass. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::captured();
    }
}
