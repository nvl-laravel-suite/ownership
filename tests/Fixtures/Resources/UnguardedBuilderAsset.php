<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Owned model whose custom builder leaves scope-free writes unbounded. */
final class UnguardedBuilderAsset extends Model implements Ownable
{
    use HasOwnership;

    protected $table = 'ownership_test_unguarded_assets';

    /**
     * @param  QueryBuilder  $query
     * @return UnguardedAssetBuilder<*>
     */
    public function newEloquentBuilder($query): UnguardedAssetBuilder
    {
        return new UnguardedAssetBuilder($query);
    }

    /** Declare the host's existing native owner key. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(Company::class, 'company_id');
    }
}
