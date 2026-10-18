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
    /** Authorize maintenance independently of the supplied audit facts. */
    public function authorize(OwnershipOperation $operation): void;
}
