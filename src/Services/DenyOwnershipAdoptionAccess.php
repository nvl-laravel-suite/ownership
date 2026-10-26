<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * No audit fact or CLI entry grants adoption authority implicitly.
 *
 * @internal
 */
final readonly class DenyOwnershipAdoptionAccess implements OwnershipAdoptionAccess
{
    /** Require an explicit host authorization adapter. */
    public function authorize(OwnershipOperation $operation): void
    {
        throw new OwnershipViolation('A host maintenance authorization adapter is required.');
    }
}
