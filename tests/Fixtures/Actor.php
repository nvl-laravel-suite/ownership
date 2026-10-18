<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use Illuminate\Foundation\Auth\User;

/**
 * Supplies ordinary Laravel authentication without NVL Auth.
 *
 * @property int $id
 * @property string $name
 */
final class Actor extends User
{
    protected $table = 'ownership_test_actors';

    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;
}
