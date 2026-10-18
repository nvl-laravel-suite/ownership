<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Native-key resource with a concrete owner column. */
class ColumnAsset extends Model implements Ownable
{
    use HasOwnership;

    protected $table = 'ownership_test_column_assets';

    protected $guarded = [];

    public $timestamps = false;

    /** Declare the host's existing native owner key. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(Company::class, 'company_id');
    }
}
