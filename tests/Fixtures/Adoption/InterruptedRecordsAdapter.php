<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use RuntimeException;

class InterruptedRecordsAdapter extends RecordsAdapter
{
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $result = parent::backfill($plan, $cursor, $limit);
        if (config('ownership_test.interrupt_backfill') === $this->resources()[0]) {
            config(['ownership_test.interrupt_backfill' => null]);
            throw new RuntimeException('Interrupted after a committed source page.');
        }

        return $result;
    }
}
