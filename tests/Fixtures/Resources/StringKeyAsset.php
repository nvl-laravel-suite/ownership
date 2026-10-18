<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

/** An independently owned parent with lossless native string record identifiers. */
final class StringKeyAsset extends ColumnAsset
{
    protected $table = 'ownership_test_string_key_assets';

    protected $keyType = 'string';

    public $incrementing = false;
}
