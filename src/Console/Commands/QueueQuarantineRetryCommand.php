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
        $input = $this->argument('id');
        if (! is_array($input) || ! array_is_list($input) || $input === []) {
            $this->error('Native failed-job IDs must be a nonempty list of strings.');

            return self::FAILURE;
        }
        $identifiers = [];
        foreach ($input as $id) {
            if (! is_string($id) || $id === '') {
                $this->error('Native failed-job IDs must be a nonempty list of strings.');

                return self::FAILURE;
            }
            $identifiers[] = $id;
        }
        foreach ($identifiers as $id) {
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
