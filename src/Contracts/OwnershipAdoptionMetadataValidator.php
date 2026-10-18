<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Nvl\Ownership\ValueObjects\OwnershipMapping;

/**
 * Optionally validates exact package-owned assignment metadata before preparation.
 *
 * @api
 */
interface OwnershipAdoptionMetadataValidator
{
    /** Reject unknown fields, invalid destination references, and confidential payloads. */
    public function validateAssignment(OwnershipMapping $assignment): void;
}
