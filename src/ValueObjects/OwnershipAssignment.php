<?php

declare(strict_types=1);

namespace Nvl\Ownership\ValueObjects;

/**
 * Supplies server-generated fields inside one coordinated persistence callback.
 *
 * @api
 */
final readonly class OwnershipAssignment
{
    /** @param array<string, int|string|null> $values */
    public function __construct(private array $values) {}

    /** @return array<string, int|string|null> */
    public function attributes(): array
    {
        return $this->values;
    }
}
