<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;

/** Activates a fully reverified graph through fresh host cutover authorization. */
final class ActivateOwnershipMigrationCommand extends OwnershipMigrationCommand
{
    protected $signature = 'nvl:ownership:activate {--plan= : Persisted plan UUID} {--actor-type=} {--actor-id=}';

    protected $description = 'Activate a verified ownership graph under maintenance and host authorization';

    /** Publish the coordinated generation without dropping legacy data or history. */
    public function handle(OwnershipAdoptionCoordinator $coordinator): int
    {
        $plan = $coordinator->resume($this->planId($this->option('plan')));
        $coordinator->activate($plan, $this->operation('activate', $this->option('actor-type'), $this->option('actor-id')));
        $this->line(json_encode(['id' => $plan->id, 'active' => true], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
