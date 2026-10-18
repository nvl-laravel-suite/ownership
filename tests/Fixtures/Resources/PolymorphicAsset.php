<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** A package-controlled child with a finite canonical parent allowlist. */
final class PolymorphicAsset extends ColumnAsset
{
    protected $table = 'ownership_test_polymorphic_assets';

    /** Derive ownership from persisted subject identity only. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::inherited('subject');
    }

    /** @return MorphTo<Model, $this> */
    public function subject(): MorphTo
    {
        return $this->morphTo('subject');
    }
}
