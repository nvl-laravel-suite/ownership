<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

/** Executes the native listener wrapper under its event's captured ownership. */
final class ProbeListener implements ShouldQueue
{
    /** Select the actual native transport used by this process proof. */
    public function viaConnection(): string
    {
        $driver = config('ownership-worker.driver', 'database');

        return is_string($driver) ? $driver : 'database';
    }

    /** Record fresh owner admission before native listener work. */
    public function handle(ProbeEvent $event): void
    {
        DB::table('ownership_queue_probes')->insert(['stage' => 'listener', 'owner' => app(OwnershipContext::class)->requireOwner()->key, 'label' => 'event-A']);
    }
}
