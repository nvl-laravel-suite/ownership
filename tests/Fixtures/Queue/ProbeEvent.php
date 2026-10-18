<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;

/** Carries producer capture through the native queued-listener adapter. */
final class ProbeEvent implements OwnershipQueuedJob
{
    use CapturesOwnership;

    /** Capture before the native event dispatcher selects queued listeners. */
    public function __construct()
    {
        $this->captureOwnership();
    }
}
