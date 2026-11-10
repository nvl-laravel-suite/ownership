<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use RuntimeException;

/** Proves a sequential global handler cannot inherit protected owner authority. */
final class UnownedStateJob implements OwnershipQueuedJob, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(private readonly OwnershipJobEnvelope $envelope) {}

    /** Carry only explicit zero-authority capture. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->envelope;
    }

    /** Record global execution only after a protected model read is refused. */
    public function handle(): void
    {
        try {
            ColumnAsset::query()->count();
        } catch (OwnershipViolation) {
            QueueStateProbe::record('unowned', 'global');

            return;
        }

        throw new RuntimeException('The global queued handler inherited protected owner access.');
    }
}
