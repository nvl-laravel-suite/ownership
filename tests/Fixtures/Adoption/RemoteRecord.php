<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

/** Same native key space on a separate source database. */
final class RemoteRecord extends Record
{
    protected $connection = 'ownership_remote';
}
