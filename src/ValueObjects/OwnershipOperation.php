<?php

declare(strict_types=1);

namespace Nvl\Ownership\ValueObjects;

use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Bounded audit facts; these grant no authority by themselves.
 *
 * @api
 */
final readonly class OwnershipOperation
{
    /** Require deliberate nonempty operation and principal facts. */
    public function __construct(public string $purpose, public string $actorType, public string $actorId)
    {
        foreach ([$purpose, $actorType, $actorId] as $value) {
            if (trim($value) === '' || mb_strlen($value) > 255 || str_contains($value, "\0")) {
                throw new OwnershipConfigurationInvalid('Operation facts require 1 to 255 characters.');
            }
        }
    }
}
