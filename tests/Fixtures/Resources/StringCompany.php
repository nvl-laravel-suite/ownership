<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Model;

/** Host owner whose native string keys must preserve leading zeroes. */
class StringCompany extends Model
{
    protected $table = 'ownership_test_string_companies';

    protected $keyType = 'string';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
