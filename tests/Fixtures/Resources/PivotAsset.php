<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** A concrete native-owner pivot must have the same exclusivity as a column. */
final class PivotAsset extends ColumnAsset
{
    protected $table = 'ownership_test_pivot_assets';

    /** Declare one exclusive native owner through the host pivot. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::pivot(Company::class, 'ownership_test_asset_owners', 'asset_id', 'company_id');
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class, 'ownership_test_asset_owners', 'asset_id', 'company_id');
    }
}
