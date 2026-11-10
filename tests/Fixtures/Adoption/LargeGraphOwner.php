<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Illuminate\Database\Eloquent\Model;

/** Counts native owner metadata construction without instrumenting production services. */
class LargeGraphOwner extends Model
{
    public static int $constructions = 0;

    protected $table = 'ownership_test_companies';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        self::$constructions++;
        parent::__construct($attributes);
    }
}
