<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;

/**
 * Owns idempotent package schema and canonical data adoption under coordinator maintenance.
 *
 * @api
 */
interface OwnershipAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array;

    /** Prepare and validate the package's nullable ownership schema idempotently. */
    public function prepare(OwnershipAdoptionPlan $plan): void;

    /** Process at most the supplied limit using stable primary-key progress. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult;

    /**
     * Inspect actual storage and canonical parents without mutating records.
     *
     * @phpstan-impure
     */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification;

    /** Apply and validate final constraints idempotently before any active marker is published. */
    public function activate(OwnershipAdoptionPlan $plan): void;
}
