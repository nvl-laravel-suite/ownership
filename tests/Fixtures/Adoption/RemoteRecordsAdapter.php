<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

/** Independent package adapter for the second source database. */
final class RemoteRecordsAdapter extends RecordsAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['remote.records'];
    }
}
