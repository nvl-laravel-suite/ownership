<?php

declare(strict_types=1);

namespace Nvl\Ownership\ValueObjects;

use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Reviewed per-record assignment with an optional legacy identity.
 *
 * @api
 */
final readonly class OwnershipMapping
{
    /** @param array<string, mixed> $metadata */
    public function __construct(public string $resource, public string $recordId, public ?OwnerReference $owner, public array $metadata = [], public ?string $legacyTenantId = null) {}

    /** Require a native owner in adapters whose resource disallows central records. */
    public function requireOwner(): OwnerReference
    {
        return $this->owner ?? throw new OwnershipConfigurationInvalid('This mapping requires an explicit native owner.');
    }
}
