<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Queue\Events\JobAttempted;
use Illuminate\Support\ServiceProvider;

/** Adds complete post-attempt observations to the existing host policy worker fixture. */
final class SequentialWorkerProvider extends ServiceProvider
{
    /** Observe completion before Laravel forgets the next loop's scoped services. */
    public function boot(): void
    {
        $this->app->setLocale('en');
        $this->app->make('events')->listen(JobAttempted::class, static function (JobAttempted $event): void {
            QueueStateProbe::record('attempted', $event->job->uuid() ?? 'unknown');
        });
    }
}
