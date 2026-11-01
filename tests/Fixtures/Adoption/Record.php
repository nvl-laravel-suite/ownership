<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Illuminate\Database\Eloquent\Model;

/** Native source record used to exercise actual adoption storage. */
class Record extends Model
{
    protected $table = 'ownership_test_records';

    protected $guarded = [];

    public $incrementing = false;

    public $timestamps = false;

    /** Return the persisted scalar source identity without coercing objects or null. */
    public function nativeId(): string
    {
        $key = $this->getKey();
        if (! is_int($key) && ! is_string($key)) {
            throw new \RuntimeException('Source primary key is not scalar.');
        }

        return (string) $key;
    }
}
