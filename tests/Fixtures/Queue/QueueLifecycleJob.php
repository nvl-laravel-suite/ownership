<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;
use RuntimeException;
use Throwable;

/** Exercises native chain, batch, middleware and terminal restoration with immutable capture. */
final class QueueLifecycleJob implements OwnershipQueuedJob, ShouldQueue
{
    use Batchable;
    use CapturesOwnership;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels {
        __unserialize as private restoreSerializedModels;
    }

    public const string OVERLAP_IDENTITY = 'same-business-key';

    public int $tries = 1;

    /** Capture before either a chain or a transaction can defer native publication. */
    public function __construct(public string $label, public bool $throws = false, public bool $overlap = false)
    {
        $this->captureOwnership();
    }

    /** Observe native deserialization independently of handle and failure callbacks.
     * @param  array<string, mixed>  $values
     */
    public function __unserialize(array $values): void
    {
        QueueStateProbe::record('restore', is_string($values['label'] ?? null) ? $values['label'] : 'unknown');
        $this->restoreSerializedModels($values);
    }

    /** @return list<WithoutOverlapping> */
    public function middleware(): array
    {
        return $this->overlap
            ? [(new WithoutOverlapping($this->ownershipLockKey(self::OVERLAP_IDENTITY)))->dontRelease()->expireAfter(60)]
            : [];
    }

    /** Record real handler entry before an optional terminal fixture failure. */
    public function handle(): void
    {
        QueueStateProbe::record('handle', $this->label);
        if ($this->throws) {
            throw new RuntimeException('Queue lifecycle fixture failure.');
        }
    }

    /** Observe the native terminal callback independently of the original command object. */
    public function failed(?Throwable $exception): void
    {
        QueueStateProbe::record('failed', $this->label);
    }
}
