<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use stdClass;

/** Applies reviewed assignments in bounded native resource pages without changing package verification. @internal */
final readonly class OwnershipResourceBackfill
{
    /** Retain canonical resource metadata and immutable reviewed mappings. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipAdoptionStore $mappings) {}

    /**
     * Traverse the package's stable resource order without counting empty tables as progress.
     *
     * @param  list<string>  $keys
     * @param  (Closure(string, Model, stdClass, OwnershipMapping): array<string, mixed>)|null  $transform
     */
    public function run(OwnershipAdoptionPlan $plan, array $keys, ?string $cursor, int $limit, string $label, ?Closure $transform = null): OwnershipBackfillResult
    {
        $processed = 0;
        do {
            $result = $this->page($plan, $keys, $cursor, $limit - $processed, $label, $transform);
            $processed += $result->processed;
            $cursor = $result->nextCursor;
        } while ($cursor !== null && $processed < $limit);

        return new OwnershipBackfillResult($cursor, $processed);
    }

    /**
     * Assign one native page atomically, preserving the 6.x position-and-primary-key cursor.
     *
     * @param  list<string>  $keys
     * @param  (Closure(string, Model, stdClass, OwnershipMapping): array<string, mixed>)|null  $transform
     */
    private function page(OwnershipAdoptionPlan $plan, array $keys, ?string $cursor, int $limit, string $label, ?Closure $transform): OwnershipBackfillResult
    {
        $parts = $cursor === null ? ['0', ''] : explode(':', $cursor, 2);
        if (count($parts) !== 2 || preg_match('/^[0-9]+$/D', $parts[0]) !== 1) {
            throw new OwnershipConfigurationInvalid('Invalid '.$label.' adoption cursor.');
        }
        $position = (int) $parts[0];
        $key = $keys[$position] ?? throw new OwnershipConfigurationInvalid('Invalid '.$label.' adoption resource.');
        $definition = $this->resources->get($key);
        $model = new $definition->model;
        $connection = $model->getConnection();
        $recordKey = $model->getKeyName();
        $rows = $connection->table($model->getTable())->when($parts[1] !== '', static fn ($query) => $query->where($recordKey, '>', $parts[1]))->orderBy($recordKey)->limit($limit)->get();
        $last = '';
        $connection->transaction(function () use ($rows, $key, $plan, $definition, $model, $connection, $recordKey, $label, $transform, &$last): void {
            foreach ($rows as $row) {
                $id = $row->{$recordKey};
                if (! is_string($id)) {
                    throw new OwnershipConfigurationInvalid($label.' adoption requires canonical UUID records.');
                }
                $mapping = $this->mappings->forRecord($plan, $key, $id);
                $owner = $mapping->owner;
                if (isset($row->tenant_id) && $mapping->legacyTenantId !== $row->tenant_id) {
                    throw new OwnershipConfigurationInvalid('Reviewed '.$label.' mappings must preserve the original tenant identity.');
                }
                $ownership = $definition->ownership;
                $nativeOwner = $ownership->foreignKey === null ? null : ($row->{$ownership->foreignKey} ?? null);
                if ($nativeOwner !== null && ((! is_int($nativeOwner) && ! is_string($nativeOwner)) || (string) $nativeOwner !== $owner?->key)) {
                    throw new OwnershipConfigurationInvalid($label.' adoption cannot relabel an existing native primary owner.');
                }
                $attributes = ['owner_scope_key' => $owner?->scopeKey() ?? 'central'];
                if ($ownership->strategy === 'captured') {
                    $attributes['owner_reference'] = $owner === null ? null : json_encode($owner->toArray(), JSON_THROW_ON_ERROR);
                } elseif ($ownership->strategy === 'column') {
                    $attributes[$ownership->foreignKey ?? throw new OwnershipConfigurationInvalid] = $owner?->key;
                } elseif ($ownership->strategy === 'pivot' && $owner !== null) {
                    $connection->table($ownership->table ?? throw new OwnershipConfigurationInvalid)->insertOrIgnore([
                        $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid => $id,
                        $ownership->ownerKey ?? throw new OwnershipConfigurationInvalid => $owner->key,
                        'owner_scope_key' => $owner->scopeKey(),
                    ]);
                }
                if ($transform !== null) {
                    $attributes = [...$transform($key, $model, $row, $mapping), ...$attributes];
                }
                $connection->table($model->getTable())->where($recordKey, $id)->update($attributes);
                $last = $id;
            }
        });
        $next = $rows->count() === $limit ? $position.':'.$last : ($position + 1 < count($keys) ? ($position + 1).':' : null);

        return new OwnershipBackfillResult($next, $rows->count());
    }
}
