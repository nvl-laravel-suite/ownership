<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

final class InterruptedRemoteRecordsAdapter extends InterruptedRecordsAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['remote.records'];
    }
}
