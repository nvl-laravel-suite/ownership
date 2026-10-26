<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Support\Str;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Persists authorized facts before any privileged callback.
 *
 * @internal
 */
final readonly class OwnershipOperationRecorder
{
    /** Resolve the live coordinator connection. */
    public function __construct(private OwnershipConnections $connections) {}

    /** Persist independently from caller-owned transactions or fail before the callback. */
    public function record(OwnershipOperation $operation, ?OwnerReference $owner = null): void
    {
        $connection = $this->connections->core();
        foreach ($this->connections->participating() as $participating) {
            if ($participating->transactionLevel() !== 0) {
                throw new OwnershipViolation('Privileged audit must precede every participating transaction.');
            }
        }
        $this->connections->identity($connection);
        if (! $connection->getSchemaBuilder()->hasTable(Tables::Operations)) {
            throw new OwnershipSchemaNotReady('The durable ownership audit store is not installed.');
        }
        $connection->table(Tables::Operations)->insert([
            'id' => (string) Str::uuid(), 'actor_type' => $operation->actorType, 'actor_id' => $operation->actorId,
            'purpose' => $operation->purpose, 'owner_reference' => $owner === null ? null : json_encode($owner->toArray(), JSON_THROW_ON_ERROR), 'created_at' => now(),
        ]);
    }
}
