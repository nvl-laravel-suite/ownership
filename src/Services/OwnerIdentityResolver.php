<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Resolves only declared canonical owner models with native persisted identifiers.
 *
 * @api
 */
final readonly class OwnerIdentityResolver
{
    /** Retain identity metadata without caching owners or queries across operations. */
    public function __construct(private OwnerRegistry $owners, private Repository $configuration, private OwnershipConnections $connections) {}

    /** Derive identity from an exact declared model and its canonical storage. */
    public function reference(Model $owner): OwnerReference
    {
        $identities = [];

        return $this->derive($owner, $identities);
    }

    /** Reload a reference through its allowlisted class before access checks. */
    public function resolve(OwnerReference $reference): Model
    {
        $identities = [];
        $class = $this->owners->model($reference->type);
        $canonical = new $class;
        if ($this->owners->aliasFor($canonical) !== $reference->type
            || $this->storage($canonical, $identities) !== $reference->storage
            || $canonical->getKeyType() !== $reference->keyType) {
            throw new OwnershipViolation;
        }
        $owner = $canonical->newQuery()->useWritePdo()->whereKey($reference->key)->first();
        $key = $owner?->getKey();
        if ($owner === null || (! is_int($key) && ! is_string($key))
            || (string) $key !== $reference->key || $this->derive($owner, $identities) != $reference) {
            throw new OwnershipViolation;
        }

        return $owner;
    }

    /**
     * Derive one reference using the store identities already read by the current operation.
     *
     * @param  array<int, string|null>  $identities
     */
    private function derive(Model $owner, array &$identities): OwnerReference
    {
        $class = $this->owners->model($owner::class);
        $canonical = new $class;
        if ($owner::class !== $class || ! $owner->exists || $owner->getKey() === null
            || $owner->getTable() !== $canonical->getTable()
            || $this->storage($owner, $identities) !== $this->storage($canonical, $identities)
            || $owner->isDirty($owner->getKeyName())) {
            throw new OwnershipViolation;
        }
        $key = $owner->getRawOriginal($owner->getKeyName());
        if ((! is_string($key) && ! is_int($key))
            || ($canonical->getKeyType() === 'int' && preg_match('/^(?:0|-?[1-9][0-9]*)$/D', (string) $key) !== 1)) {
            throw new OwnershipViolation;
        }

        return new OwnerReference($this->owners->aliasFor($owner), $this->storage($canonical, $identities), $canonical->getKeyType(), (string) $key);
    }

    /**
     * Use durable store identity once installed, reading each actual connection once per operation.
     *
     * @param  array<int, string|null>  $identities
     */
    private function storage(Model $owner, array &$identities): string
    {
        $connection = $owner->getConnectionName() ?? $this->configuration->get('database.default');
        if (! is_string($connection) || $connection === '') {
            throw new OwnershipViolation;
        }
        $source = $owner->getConnection();
        $id = spl_object_id($source);
        if (! array_key_exists($id, $identities)) {
            $identities[$id] = $this->connections->optionalIdentity($source);
        }

        return ($identities[$id] ?? $connection).'.'.$owner->getTable();
    }
}
