<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Builds real enforced storage through the adoption protocol in every resource test. */
final readonly class AssetsAdapter implements OwnershipAdoptionAdapter
{
    /** Resolve package metadata and the explicitly scoped input store. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipAdoptionStore $mappings) {}

    /** @return list<string> */
    public function resources(): array
    {
        return ['assets.column', 'assets.pivot', 'assets.captured', 'assets.child', 'assets.string', 'assets.string-key', 'assets.poly'];
    }

    /** Add only the shared partition structures; native owner schemas remain host-owned. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $resource) {
            app(OwnershipSchema::class)->prepare($resource);
        }
    }

    /** Assign a fixed tiny fixture graph from reviewed full references. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $processed = 0;
        foreach ($this->resources() as $resource) {
            $definition = $this->resources->get($resource);
            $model = new $definition->model;
            $connection = $model->getConnection();
            foreach ($connection->table($model->getTable())->orderBy('id')->get() as $row) {
                if (! is_int($row->id) && ! is_string($row->id)) {
                    throw new \RuntimeException('Fixture IDs must be native scalar keys.');
                }
                $mapping = $this->mappings->forRecord($plan, $resource, (string) $row->id);
                $attributes = ['owner_scope_key' => $mapping->requireOwner()->scopeKey()];
                if ($definition->ownership->strategy === 'captured') {
                    $attributes['owner_reference'] = json_encode($mapping->requireOwner()->toArray(), JSON_THROW_ON_ERROR);
                }
                $connection->table($model->getTable())->where('id', $row->id)->update($attributes);
                if ($definition->ownership->strategy === 'pivot') {
                    $connection->table('ownership_test_asset_owners')->insertOrIgnore(['asset_id' => $row->id, 'company_id' => $mapping->requireOwner()->key, 'owner_scope_key' => $mapping->requireOwner()->scopeKey()]);
                }
                $processed++;
            }
        }

        return new OwnershipBackfillResult(null, $processed);
    }

    /** Inspect actual assignment and schema facts before the coordinator can publish readiness. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        foreach ($this->resources() as $resource) {
            app(OwnershipSchema::class)->verify($resource);
        }

        return new OwnershipVerification([]);
    }

    /** Apply final immutable constraints before activation. */
    public function activate(OwnershipAdoptionPlan $plan): void
    {
        foreach ($this->resources() as $resource) {
            app(OwnershipSchema::class)->constrain($resource);
        }
    }
}
