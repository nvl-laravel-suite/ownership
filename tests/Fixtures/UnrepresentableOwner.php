<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;

/**
 * Deliberately miscasts persisted keys to exercise admission rejection.
 *
 * @property int|string $id
 * @property bool $active
 */
final class UnrepresentableOwner extends Model
{
    protected $table = 'ownership_test_unrepresentable_owners';

    public $timestamps = false;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['active' => 'boolean'];
    }
}
