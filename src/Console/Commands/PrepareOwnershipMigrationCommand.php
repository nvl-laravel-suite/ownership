<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipMigrationInput;

/** Prepares only the reviewed immutable graph through the existing audited coordinator. */
final class PrepareOwnershipMigrationCommand extends OwnershipMigrationCommand
{
    protected $signature = 'nvl:ownership:prepare {--plan= : Reviewed plan UUID} {--input= : Reviewed plan JSON} {--actor-type=} {--actor-id=}';

    protected $description = 'Prepare additive ownership storage from an explicitly reviewed plan';

    /** Recheck deployment identities under the source leases before any DDL. */
    public function handle(OwnershipMigrationInput $input, OwnershipAdoptionCoordinator $coordinator): int
    {
        $review = $input->reviewed($this->inputPath($this->option('input')), $this->planId($this->option('plan')));
        $plan = $coordinator->prepare($review->packages, $review->mappings, $this->operation('prepare', $this->option('actor-type'), $this->option('actor-id')), $review);
        $this->line(json_encode(['id' => $plan->id, 'mapping_hash' => $plan->mappingHash, 'configuration_hash' => $plan->configurationHash], JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
