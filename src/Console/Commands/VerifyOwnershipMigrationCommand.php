<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;

/** Reports actual conservation and schema checks without authorizing or recording activation. */
final class VerifyOwnershipMigrationCommand extends OwnershipMigrationCommand
{
    protected $signature = 'nvl:ownership:verify {--plan= : Persisted plan UUID}';

    protected $description = 'Verify ownership migration conservation and actual constraints without mutation';

    /** Print per-resource proof and return failure for any incomplete or inconsistent resource. */
    public function handle(OwnershipAdoptionCoordinator $coordinator): int
    {
        $plan = $coordinator->resume($this->planId($this->option('plan')));
        $report = $coordinator->verify($plan);
        $this->line(json_encode(['id' => $plan->id, 'passed' => $report->passed(), 'errors' => $report->errors, 'resources' => $report->resources], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        return $report->passed() ? self::SUCCESS : self::FAILURE;
    }
}
