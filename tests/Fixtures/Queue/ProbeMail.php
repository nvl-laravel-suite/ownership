<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;

/** Uses Laravel's real queued mail wrapper and array transport. */
final class ProbeMail extends Mailable implements OwnershipQueuedJob, ShouldQueue
{
    use CapturesOwnership;
    use Queueable;

    /** Capture before native delivery is scheduled. */
    public function __construct()
    {
        $this->captureOwnership();
    }

    /** Observe delivery inside the worker's freshly admitted owner scope. */
    public function build(): self
    {
        DB::table('ownership_queue_probes')->insert(['stage' => 'mail', 'owner' => app(OwnershipContext::class)->requireOwner()->key, 'label' => 'mail-A']);

        return $this->html('Ownership worker mail proof.');
    }
}
