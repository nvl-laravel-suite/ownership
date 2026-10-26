<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Resolves a single concrete primary-owner row independently of loaded pivots.
 *
 * @internal
 */
final readonly class PivotOwnershipResolver
{
    /** Reuse canonical native-key validation. */
    public function __construct(private ColumnOwnershipResolver $columns) {}

    /** Require exactly one persisted owner row with the same root partition. */
    public function reference(Model $record, OwnershipResourceDefinition $resource, bool $lock): ?OwnerReference
    {
        $definition = $resource->ownership;
        $query = $record->getConnection()->table($definition->table ?? throw new OwnershipConfigurationInvalid)->useWritePdo()
            ->where($definition->resourceKey ?? throw new OwnershipConfigurationInvalid, $record->getRawOriginal($record->getKeyName()));
        if ($lock) {
            $query->lockForUpdate();
        }
        $rows = $query->limit(2)->get();
        if ($rows->isEmpty() && $resource->allowsCentral && $record->getRawOriginal('owner_scope_key') === 'central') {
            return null;
        }
        $row = $rows->first();
        if ($rows->count() !== 1 || $row === null || $row->owner_scope_key !== $record->getRawOriginal('owner_scope_key')) {
            throw new OwnershipViolation('The primary ownership pivot is missing, ambiguous, or mismatched.');
        }
        $ownerKey = $definition->ownerKey ?? throw new OwnershipConfigurationInvalid;

        return $this->columns->owner($record, $definition->owner ?? throw new OwnershipConfigurationInvalid, $row->{$ownerKey}, $lock);
    }
}
