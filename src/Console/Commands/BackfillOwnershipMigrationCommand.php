<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/** Runs one bounded resumable backfill batch from the persisted immutable plan. */
final class BackfillOwnershipMigrationCommand extends OwnershipMigrationCommand
{
    protected $signature = 'nvl:ownership:backfill {--plan= : Persisted plan UUID} {--limit=500 : Maximum records per adapter} {--actor-type=} {--actor-id=}';

    protected $description = 'Backfill one bounded ownership migration batch';

    /** Resume exact stored assignments and report whether all adapters finished. */
    public function handle(OwnershipAdoptionCoordinator $coordinator): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 10000]]);
        if (! is_int($limit)) {
            throw new OwnershipConfigurationInvalid('Backfill --limit must be an integer from 1 to 10000.');
        }
        $plan = $coordinator->resume($this->planId($this->option('plan')));
        $done = $coordinator->backfill($plan, $limit, $this->operation('backfill', $this->option('actor-type'), $this->option('actor-id')));
        $this->line(json_encode(['id' => $plan->id, 'done' => $done], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
