<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

/** Proves explicit global transport has no access to protected resources. */
final class UnownedProbeJob implements OwnershipQueuedJob, ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(private readonly OwnershipJobEnvelope $envelope) {}

    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->envelope;
    }

    public function handle(): void
    {
        try {
            ColumnAsset::query()->get();
            throw new \RuntimeException('Unowned transport exposed protected assets.');
        } catch (OwnershipViolation) {
            DB::table('ownership_queue_probes')->insert(['stage' => 'unowned-handle', 'owner' => app(OwnershipContext::class)->snapshot()->mode->value, 'label' => 'unowned']);
        }
    }
}
