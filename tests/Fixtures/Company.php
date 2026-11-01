<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Represents a native host owner without any tenant or ownership trait.
 *
 * @property int $id
 * @property bool $active
 */
final class Company extends Model
{
    protected $table = 'ownership_test_companies';

    /** @var list<string> */
    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
