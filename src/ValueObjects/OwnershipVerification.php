<?php

declare(strict_types=1);

namespace Nvl\Ownership\ValueObjects;

/**
 * Reports bounded ownership/schema validation failures without record payloads.
 *
 * @phpstan-type ResourceReport array{source_store:string, source_table:string, target_table:string, source:int, persisted:int, mapped:int, owners:array<string,int>}
 *
 * @api
 */
final readonly class OwnershipVerification
{
    /** @param list<string> $errors
     * @param  array<string,ResourceReport>  $resources
     */
    public function __construct(public array $errors, public array $resources = []) {}

    /** Determine whether all ownership checks passed. */
    public function passed(): bool
    {
        return $this->errors === [];
    }
}
