<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Resolves native column ownership through the declared canonical owner model. @internal */
final readonly class ColumnOwnershipResolver
{
    /** Retain identity services without caching owners or current context. */
    public function __construct(private OwnerIdentityResolver $identities, private OwnerRegistry $owners) {}

    /** Resolve exact native key bytes and reject cross-connection columns. */
    public function reference(Model $record, OwnershipResourceDefinition $resource, bool $lock): ?OwnerReference
    {
        $definition = $resource->ownership;
        $class = $definition->owner ?? throw new OwnershipConfigurationInvalid;
        $key = $record->getRawOriginal($definition->foreignKey ?? throw new OwnershipConfigurationInvalid);
        if ($key === null && $record->getRawOriginal('owner_scope_key') === 'central' && $resource->allowsCentral) {
            return null;
        }

        return $this->owner($record, $class, $key, $lock);
    }

    /** @param class-string<Model> $class */
    public function owner(Model $resource, string $class, mixed $key, bool $lock): OwnerReference
    {
        $class = $this->owners->model($class);
        $canonical = new $class;
        if ((! is_int($key) && ! is_string($key)) || $canonical->getConnection() !== $resource->getConnection()) {
            throw new OwnershipViolation('Native owner columns and pivots require the same actual connection.');
        }
        $query = $canonical->newQuery()->useWritePdo()->whereKey($key);
        if ($lock) {
            $query->lockForUpdate();
        }
        $owner = $query->first();
        if ($owner === null) {
            throw new OwnershipViolation;
        }
        $reference = $this->identities->reference($owner);
        if ($reference->key !== (string) $key) {
            throw new OwnershipViolation;
        }

        return $reference;
    }
}
