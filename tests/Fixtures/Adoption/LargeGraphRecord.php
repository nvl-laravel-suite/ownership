<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

/** Shares native source behavior across the bounded generated resource types. */
class LargeGraphRecord extends Record
{
    public static int $constructions = 0;

    /** @param array<string, mixed> $attributes */
    public function __construct(array $attributes = [])
    {
        self::$constructions++;
        parent::__construct($attributes);
    }
}
