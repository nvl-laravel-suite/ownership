<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Traits\CapturesOwnership;

/** Delivers through a real native notification wrapper with scalar recipient routes. */
final class ProbeNotification extends Notification implements OwnershipQueuedJob, ShouldQueue
{
    use CapturesOwnership;
    use Queueable;

    /** Capture before any notification channel can be scheduled. */
    public function __construct()
    {
        $this->captureOwnership();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** Record the admitted scope during native delivery. */
    public function toMail(object $notifiable): MailMessage
    {
        DB::table('ownership_queue_probes')->insert(['stage' => 'notification', 'owner' => app(OwnershipContext::class)->requireOwner()->key, 'label' => 'notification-A']);

        return (new MailMessage)->line('Ownership worker notification proof.');
    }
}
