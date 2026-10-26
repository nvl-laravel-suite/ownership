<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/**
 * Reconstructs complete ownership facts and validates their redundant index partition.
 *
 * @internal
 */
final readonly class OwnershipCanonicalResolver
{
    /** Compose focused persistence resolvers. */
    public function __construct(private OwnershipRecordReader $records, private ColumnOwnershipResolver $columns, private PivotOwnershipResolver $pivots, private CapturedOwnershipResolver $captured, private InheritedOwnershipResolver $inherited) {}

    /** @param list<string> $visited */
    public function reference(Model $record, OwnershipResourceDefinition $resource, bool $lock = false, array $visited = []): ?OwnerReference
    {
        return $this->fromPersisted($this->records->persisted($record, $resource, $lock), $resource, $lock, $visited);
    }

    /** Resolve one already-read canonical row without reopening a primary-key reuse window.
     * @param  list<string>  $visited
     */
    public function fromPersisted(Model $fresh, OwnershipResourceDefinition $resource, bool $lock = false, array $visited = []): ?OwnerReference
    {
        $this->records->assertModel($fresh, $resource);
        if (in_array($resource->key, $visited, true)) {
            throw new OwnershipViolation('The canonical owner graph contains a cycle.');
        }
        $visited[] = $resource->key;
        $reference = match ($resource->ownership->strategy) {
            'column' => $this->columns->reference($fresh, $resource, $lock),
            'pivot' => $this->pivots->reference($fresh, $resource, $lock),
            'captured' => $this->captured->reference($fresh, $resource),
            'inherited' => $this->inherited->reference($fresh, $resource, $lock, $visited),
            default => throw new OwnershipViolation('An owned graph cannot inherit an unscoped resource.'),
        };
        if (($reference === null && ! $resource->allowsCentral)
            || $fresh->getRawOriginal('owner_scope_key') !== ($reference?->scopeKey() ?? 'central')) {
            throw new OwnershipViolation('The persisted full owner and its partition key differ.');
        }

        return $reference;
    }
}
