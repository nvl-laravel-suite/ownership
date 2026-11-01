<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Minimal captured resource for native partition-column DDL checks. */
final class ScopeEncodingAsset extends ColumnAsset
{
    protected $table = 'ownership_test_scope_encoding';

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::captured();
    }
}
