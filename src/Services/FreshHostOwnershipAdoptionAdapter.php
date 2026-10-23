<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Adopts empty host resources through native partition DDL and coordinator verification.
 *
 * Hosts declare resources in parent-before-child order and retain their native foreign keys.
 * Existing records require a separately reviewed mapping adapter instead of this fresh-schema helper.
 *
 * @api
 */
abstract class FreshHostOwnershipAdoptionAdapter implements OwnershipAdoptionAdapter
{
    /**
     * Resolve native schema services through the container when the coordinator creates the host adapter.
     *
     * @internal
     */
    final public function __construct(
        private readonly OwnershipResourceRegistry $registry,
        private readonly OwnershipSchema $schema,
        private readonly OwnershipAdoptionScope $scope,
    ) {}

    /** @return list<string> Declare at least one host resource, with each parent before its children. */
    abstract public function resources(): array;

    /** Prepare real native partition columns and exact host foreign key requirements. */
    final public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $this->assertFresh($plan, 'prepare');
        foreach ($this->resources() as $resource) {
            $this->schema->prepare($resource);
        }
    }

    /** Complete the coordinator's zero-record backfill without inventing readiness markers. */
    final public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $this->assertFresh($plan, 'backfill');
        if ($cursor !== null || $limit < 1) {
            throw new OwnershipConfigurationInvalid('Fresh host adoption requires an empty cursor and a positive batch limit.');
        }

        return new OwnershipBackfillResult(null, 0);
    }

    /** Verify actual storage before the coordinator checks conservation and activation. */
    final public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $this->assertFresh($plan, 'verify');
        foreach ($this->resources() as $resource) {
            $this->schema->verify($resource);
        }

        return new OwnershipVerification([]);
    }

    /** Install native immutable ownership constraints before the coordinator publishes activation. */
    final public function activate(OwnershipAdoptionPlan $plan): void
    {
        $this->assertFresh($plan, 'activate');
        foreach ($this->resources() as $resource) {
            $this->schema->constrain($resource);
        }
    }

    /** Refuse arbitrary calls and any records that require a reviewed migration workflow. */
    private function assertFresh(OwnershipAdoptionPlan $plan, string $phase): void
    {
        $this->scope->assertAdapterInvocation($plan->id, static::class, $phase);
        $resources = $this->resources();
        if ($resources === [] || count(array_unique($resources)) !== count($resources)) {
            throw new OwnershipConfigurationInvalid('Fresh host adoption requires distinct declared resources.');
        }
        foreach ($resources as $resource) {
            $definition = $this->registry->get($resource);
            $model = new $definition->model;
            if ($model->getConnection()->table($model->getTable())->useWritePdo()->exists()) {
                throw new OwnershipConfigurationInvalid('Fresh host adoption requires empty resources; existing records need reviewed mappings.');
            }
        }
    }
}
