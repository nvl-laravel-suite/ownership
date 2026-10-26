<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use stdClass;

/**
 * Reads exact persisted storage without model scopes or loaded relationship caches.
 *
 * @internal
 */
final readonly class OwnershipRecordReader
{
    /** Require the registered concrete class, native key, table and actual connection. */
    public function assertModel(Model $record, OwnershipResourceDefinition $resource): void
    {
        $canonical = new $resource->model;
        if ($record::class !== $resource->model || $record->getTable() !== $canonical->getTable()
            || $record->getConnection() !== $canonical->getConnection()
            || $record->getKeyName() !== $canonical->getKeyName() || $record->getKeyType() !== $canonical->getKeyType()) {
            throw new OwnershipViolation('The model differs from its canonical resource storage.');
        }
    }

    /** Return the exact physical row, acquiring a lock only inside its source transaction. */
    public function persisted(Model $record, OwnershipResourceDefinition $resource, bool $lock = false): Model
    {
        $this->assertModel($record, $resource);
        $key = $record->getRawOriginal($record->getKeyName());
        if (! $record->exists || (! is_int($key) && ! is_string($key)) || $record->isDirty($record->getKeyName())) {
            throw new OwnershipViolation;
        }
        $canonical = new $resource->model;
        $query = $canonical->getConnection()->table($canonical->getTable())->useWritePdo()->where($canonical->getKeyName(), $key);
        if ($lock) {
            if ($canonical->getConnection()->transactionLevel() === 0) {
                throw new OwnershipViolation('Canonical write locks require the actual source transaction.');
            }
            $query->lockForUpdate();
        }
        $row = $query->first();
        if ($row === null) {
            throw new OwnershipViolation;
        }
        $fresh = $this->hydrate($canonical, $row);
        $persisted = $fresh->getRawOriginal($canonical->getKeyName());
        if ((! is_int($persisted) && ! is_string($persisted)) || (string) $persisted !== (string) $key) {
            throw new OwnershipViolation;
        }

        return $fresh;
    }

    /** Hydrate native database fields without unchecked array-key coercion.
     * @template T of Model
     *
     * @param  T  $model
     * @return T
     */
    public function hydrate(Model $model, stdClass $row): Model
    {
        $attributes = [];
        foreach (get_object_vars($row) as $name => $value) {
            if (! is_string($name) || ctype_digit($name)) {
                throw new OwnershipConfigurationInvalid('Canonical model attributes require named database columns.');
            }
            $attributes[$name] = $value;
        }

        return $model->newFromBuilder($attributes, $model->getConnectionName());
    }
}
