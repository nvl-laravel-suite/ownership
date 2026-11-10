<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;

/** Exercises constructor capture and real execution through the optional runtime's disabled lane. */
final class DisabledPublicationJob implements OwnershipQueuedJob, ShouldQueue
{
    use CapturesOwnership;

    public function __construct(public readonly string $identity)
    {
        $this->captureOwnership();
    }

    public function handle(): void
    {
        DB::table('ownership_queue_probes')->insert(['stage' => 'disabled-handle', 'owner' => app(OwnershipContext::class)->snapshot()->mode->value, 'label' => $this->identity]);
    }
}
