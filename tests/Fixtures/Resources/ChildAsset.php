<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** A concrete child whose owner can only come from its persisted canonical parent. */
final class ChildAsset extends ColumnAsset
{
    protected $table = 'ownership_test_child_assets';

    /** Retain the package-owned parent relationship. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::inherited('asset');
    }

    /** @return BelongsTo<ColumnAsset, $this> */
    public function asset(): BelongsTo
    {
        return $this->belongsTo(ColumnAsset::class, 'asset_id');
    }
}
