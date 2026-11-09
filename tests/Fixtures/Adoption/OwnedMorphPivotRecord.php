<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** A polymorphic relationship retaining Laravel's integer deletion result under ownership. */
class OwnedMorphPivotRecord extends MorphPivot implements Ownable
{
    use HasOwnership;

    protected $table = 'ownership_test_records';

    protected $keyType = 'string';

    public $timestamps = false;

    /** Declare the existing native company key without synthesizing an owner. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(Company::class, 'company_id');
    }
}
