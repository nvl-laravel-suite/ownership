<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Nvl\Ownership\Services\OwnershipMigrationInput;

/** Prints a read-only deployment plan for review and preservation by the operator. */
final class PlanOwnershipMigrationCommand extends OwnershipMigrationCommand
{
    protected $signature = 'nvl:ownership:plan {--input= : JSON packages and explicit mappings}';

    protected $description = 'Print a read-only ownership migration plan for review';

    /** Describe the exact configured graph without preparing storage or markers. */
    public function handle(OwnershipMigrationInput $input): int
    {
        $this->line(json_encode($input->plan($this->inputPath($this->option('input')))->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
