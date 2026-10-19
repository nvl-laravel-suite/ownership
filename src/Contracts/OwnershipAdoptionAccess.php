<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Nvl\Ownership\ValueObjects\OwnershipOperation;

/**
 * Host authorization for each fresh maintenance operation.
 *
 * @api
 */
interface OwnershipAdoptionAccess
{
    /**
     * Authorize the actual operator independently of audit facts and require drained producers,
     * in-flight requests and queue work before cutover; CLI actor strings are never credentials.
     */
    public function authorize(OwnershipOperation $operation): void;
}
