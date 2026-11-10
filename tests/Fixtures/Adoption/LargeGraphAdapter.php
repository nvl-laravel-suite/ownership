<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Closure;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;

/** Exercises real coordinator fences on an empty, already prepared native schema. */
class LargeGraphAdapter implements OwnershipAdoptionAdapter
{
    public static int $prepared = 0;

    protected int $group = 1;

    /** @return list<string> */
    public function resources(): array
    {
        return LargeGraphFixture::resources($this->group);
    }

    /** Keep callback effects observable without adding package-specific DDL overhead. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        self::$prepared++;
        $callback = config('ownership_test.large_graph_callback');
        if ($callback instanceof Closure) {
            $callback($this->group);
        }
    }

    /** The fixture contains no source rows or mappings. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        return new OwnershipBackfillResult(null, 0);
    }

    /** Let the coordinator's own conservation checks inspect the empty resources. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        return new OwnershipVerification([]);
    }

    /** The fixture schema is already prepared and contains no rows. */
    public function activate(OwnershipAdoptionPlan $plan): void {}
}
