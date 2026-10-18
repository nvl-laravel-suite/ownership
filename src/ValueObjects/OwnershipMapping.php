<?php

declare(strict_types=1);

namespace Nvl\Ownership\ValueObjects;

use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Reviewed per-record assignment with an optional legacy identity.
 *
 * @api
 */
final readonly class OwnershipMapping
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public string $resource, public string $recordId, public OwnerReference $owner, public array $metadata = [], public ?string $legacyTenantId = null) {}
}
