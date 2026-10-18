<?php

declare(strict_types=1);

namespace Nvl\Ownership\ValueObjects;

/**
 * Reports bounded ownership/schema validation failures without record payloads.
 *
 * @api
 */
final readonly class OwnershipVerification
{
    /** @param list<string> $errors */
    public function __construct(public array $errors) {}

    /** Determine whether all ownership checks passed. */
    public function passed(): bool
    {
        return $this->errors === [];
    }
}
