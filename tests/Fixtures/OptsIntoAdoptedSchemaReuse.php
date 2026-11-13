<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use PHPUnit\Framework\Attributes\PreCondition;

/** Enables a single-source adopted fixture for a Pest file, except explicit fresh-adoption cases. */
trait OptsIntoAdoptedSchemaReuse
{
    protected function mayReuseAdoptedSchema(): bool
    {
        return ! in_array('fresh-adoption', $this->groups(), true);
    }

    #[PreCondition]
    protected function observeAdoptedSchemaAfterSetup(): void
    {
        $this->retainAdoptedOwnershipSchema();
    }
}
