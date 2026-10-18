<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Resolves retained operational facts without requiring a surviving business subject. @internal */
final readonly class CapturedOwnershipResolver
{
    /** Revalidate the complete scalar reference through the declared host identity registry. */
    public function __construct(private OwnerIdentityResolver $identities) {}

    /** Reject partial objects, unknown morphs and matching hashes with different references. */
    public function reference(Model $record, OwnershipResourceDefinition $resource): ?OwnerReference
    {
        $value = $record->getRawOriginal('owner_reference');
        if ($value === null && $resource->allowsCentral && $record->getRawOriginal('owner_scope_key') === 'central') {
            return null;
        }
        if (! is_string($value)) {
            throw new OwnershipViolation;
        }
        $payload = json_decode($value, true, 16, JSON_THROW_ON_ERROR);
        if (! is_array($payload)) {
            throw new OwnershipViolation;
        }
        $reference = OwnerReference::fromArray($payload);
        $this->identities->resolve($reference);

        return $reference;
    }
}
