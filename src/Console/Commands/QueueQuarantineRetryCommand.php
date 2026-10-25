<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Illuminate\Console\Command;
use Nvl\Support\Ownership\Services\OwnershipQueueQuarantine;
use Throwable;

/** Requeues captured NVL work through raw transport without restoring the original command. */
final class QueueQuarantineRetryCommand extends Command
{
    /** @var string */
    protected $signature = 'nvl:queue:retry {id* : Native failed-job IDs identifying captured NVL records}';

    /** @var string */
    protected $description = 'Retry captured NVL queue envelopes without restoring captured commands';

    /** Retry the selected native records and leave rejected or unsupported records available for inspection. */
    public function handle(OwnershipQueueQuarantine $quarantine): int
    {
        foreach ($this->argument('id') as $id) {
            try {
                $quarantine->retry($id);
                $this->info("Requeued raw NVL payload [{$id}].");
            } catch (Throwable $exception) {
                $this->error($exception->getMessage());

                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
