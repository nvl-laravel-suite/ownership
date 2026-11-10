<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;
use RuntimeException;
use Throwable;

/** Observes native queued Mailable localization through successful and exceptional rendering. */
final class LocalizedStateMail extends Mailable implements OwnershipQueuedJob, ShouldQueue
{
    use CapturesOwnership;
    use Queueable;

    public int $tries = 1;

    /** Capture producer ownership before Laravel serializes its native mail wrapper. */
    public function __construct(public string $label, public bool $throws = false)
    {
        $this->captureOwnership();
    }

    /** Execute inside Laravel's temporary locale frame and the admitted ownership frame. */
    public function build(): self
    {
        QueueStateProbe::record('mail', $this->label);
        if ($this->throws) {
            throw new RuntimeException('Localized queue fixture failure.');
        }

        return $this->from('sender@example.test')->subject($this->label)->html('Localized worker state proof.');
    }

    /** Observe terminal mail failure after Laravel restores its temporary rendering locale. */
    public function failed(?Throwable $exception): void
    {
        QueueStateProbe::record('mail-failed', $this->label);
    }
}
