<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use Orchestra\Testbench\TestCase;

/** Exercises adopted-schema reuse traits during static analysis of test fixtures. */
abstract class UsesAdoptedSchemaReuse extends TestCase
{
    use OptsIntoAdoptedSchemaReuse;
    use ReusesAdoptedSchema;
}
