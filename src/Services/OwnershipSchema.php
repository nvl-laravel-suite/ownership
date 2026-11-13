<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Schema\Blueprint;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Shared partition DDL invoked explicitly by each package's adoption adapter.
 *
 * @internal
 *
 * @phpstan-type ForeignKey array{name: string|null, columns: list<string>, foreign_schema: string|null, foreign_table: string, foreign_columns: list<string>, on_update: string|null, on_delete: string|null}
 */
final readonly class OwnershipSchema
{
    /** Compose canonical storage verification and maintenance-only native constraints. */
    public function __construct(private OwnershipResourceRegistry $resources, private OwnershipAdoptionScope $scope, private OwnershipDatabaseConstraints $constraints, private OwnershipCanonicalResolver $canonical, private InheritedOwnershipResolver $parents, private OwnershipRecordReader $records, private OwnerRegistry $owners) {}

    /** Prepare additive partition columns while preserving host-owned native key definitions. */
    public function prepare(string $resource): void
    {
        $this->scope->assertSchemaAllowed();
        $definition = $this->resources->get($resource);
        $model = new $definition->model;
        $connection = $model->getConnection();
        if ($connection->transactionLevel() !== 0) {
            throw new OwnershipConfigurationInvalid('Partition DDL cannot join a caller transaction.');
        }
        $schema = $connection->getSchemaBuilder();
        $table = $model->getTable();
        $this->scopeColumn($connection, $table);
        $this->unique($connection, $table, [$model->getKeyName(), 'owner_scope_key']);
        $ownership = $definition->ownership;
        if ($ownership->strategy === 'captured' && ! $schema->hasColumn($table, 'owner_reference')) {
            $schema->table($table, static fn (Blueprint $table) => $table->json('owner_reference')->nullable());
        }
        if ($ownership->strategy === 'pivot') {
            $pivot = $ownership->table ?? throw new OwnershipConfigurationInvalid;
            if (! $schema->hasTable($pivot)) {
                throw new OwnershipSchemaNotReady('The concrete ownership pivot must be supplied by its host migration.');
            }
            $this->scopeColumn($connection, $pivot);
            $this->unique($connection, $pivot, [$ownership->resourceKey ?? throw new OwnershipConfigurationInvalid]);
        }
        if ($ownership->strategy === 'column' || $ownership->strategy === 'pivot') {
            $class = $this->owners->model($ownership->owner ?? throw new OwnershipConfigurationInvalid);
            $owner = new $class;
            if ($owner->getConnection() !== $connection) {
                throw new OwnershipConfigurationInvalid('Native ownership requires the same actual source connection.');
            }
            $target = $ownership->strategy === 'pivot' ? ($ownership->table ?? throw new OwnershipConfigurationInvalid) : $table;
            $key = $ownership->strategy === 'pivot' ? ($ownership->ownerKey ?? throw new OwnershipConfigurationInvalid) : ($ownership->foreignKey ?? throw new OwnershipConfigurationInvalid);
            $this->assertNativeKey($connection, $target, $key, $owner->getTable(), $owner->getKeyName());
            $this->assertForeign($connection, $target, [$key], $owner->getTable(), [$owner->getKeyName()]);
        }
    }

    /** Verify every actual persisted row through complete canonical ownership facts. */
    public function verify(string $resource): void
    {
        $definition = $this->resources->get($resource);
        $model = new $definition->model;
        foreach ($model->getConnection()->table($model->getTable())->useWritePdo()->orderBy($model->getKeyName())->cursor() as $row) {
            $this->canonical->reference($this->records->hydrate($model, $row), $definition);
        }
    }

    /** Apply immutable owner constraints and canonical concrete-parent partitions. */
    public function constrain(string $resource): void
    {
        $this->scope->assertSchemaAllowed();
        $this->verify($resource);
        $definition = $this->resources->get($resource);
        $model = new $definition->model;
        $connection = $model->getConnection();
        $ownership = $definition->ownership;
        if ($connection->getDriverName() !== 'sqlite') {
            $this->scopeColumn($connection, $model->getTable(), true);
            if ($ownership->strategy === 'captured' && ! $definition->allowsCentral) {
                $connection->getSchemaBuilder()->table($model->getTable(), static fn (Blueprint $table) => $table->json('owner_reference')->nullable(false)->change());
            }
        }
        $fields = array_values(array_unique([$model->getKeyName(), 'owner_scope_key', ...$definition->immutableFields]));
        $parent = null;
        $foreignKey = null;
        $parentKey = null;
        if ($ownership->strategy === 'column') {
            $fields[] = $ownership->foreignKey ?? throw new OwnershipConfigurationInvalid;
            $ownerClass = $this->owners->model($ownership->owner ?? throw new OwnershipConfigurationInvalid);
            $owner = new $ownerClass;
            if ($owner->getConnection() !== $connection) {
                throw new OwnershipConfigurationInvalid('Native owner keys require the same source connection.');
            }
            $this->assertForeign($connection, $model->getTable(), [$ownership->foreignKey], $owner->getTable(), [$owner->getKeyName()]);
        } elseif ($ownership->strategy === 'captured') {
            $fields[] = 'owner_reference';
        } elseif ($ownership->strategy === 'inherited') {
            $relation = $this->parents->relation($definition);
            $foreignKey = $relation->getForeignKeyName();
            $fields[] = $foreignKey;
            if ($relation instanceof MorphTo) {
                $fields[] = $relation->getMorphType();
            } else {
                $parentClass = $this->resources->get($definition->parents()[0])->model;
                $parentModel = new $parentClass;
                $parent = $parentModel->getTable();
                $parentKey = $parentModel->getKeyName();
                $foreignKey = $relation->getForeignKeyName();
                $this->assertForeign($connection, $model->getTable(), [$foreignKey], $parent, [$parentKey]);
                $this->partitionForeign($connection, $model->getTable(), [$foreignKey, 'owner_scope_key'], $parent, [$parentKey, 'owner_scope_key']);
            }
        }
        $this->constraints->protect($connection, $model->getTable(), $fields, $definition->allowsCentral, $parent, $foreignKey, $parentKey);
        if ($ownership->strategy === 'pivot') {
            $pivot = $ownership->table ?? throw new OwnershipConfigurationInvalid;
            if ($connection->getDriverName() !== 'sqlite') {
                $this->scopeColumn($connection, $pivot, true);
            }
            $resourceKey = $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid;
            $ownerKey = $ownership->ownerKey ?? throw new OwnershipConfigurationInvalid;
            $ownerClass = $this->owners->model($ownership->owner ?? throw new OwnershipConfigurationInvalid);
            $owner = new $ownerClass;
            if ($owner->getConnection() !== $connection) {
                throw new OwnershipConfigurationInvalid('A primary pivot cannot cross actual source connections.');
            }
            $this->assertForeign($connection, $pivot, [$ownerKey], $owner->getTable(), [$owner->getKeyName()]);
            $this->assertForeign($connection, $pivot, [$resourceKey], $model->getTable(), [$model->getKeyName()]);
            $this->partitionForeign($connection, $pivot, [$resourceKey, 'owner_scope_key'], $model->getTable(), [$model->getKeyName(), 'owner_scope_key']);
            $this->constraints->protect($connection, $pivot, [$resourceKey, $ownerKey, 'owner_scope_key'], false, $model->getTable(), $resourceKey, $model->getKeyName());
            $this->constraints->protectPivotDelete($connection, $pivot, $resourceKey, $model->getTable(), $model->getKeyName());
        }
        $this->assertReady($resource);
    }

    /** Preserve compatible partition column metadata during adoption. */
    private function scopeColumn(Connection $connection, string $table, bool $required = false): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new OwnershipConfigurationInvalid('Partition DDL cannot join a caller transaction.');
        }
        $schema = $connection->getSchemaBuilder();
        $existing = array_find($schema->getColumns($table), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
        if ($existing !== null && $existing['generation'] !== null) {
            throw new OwnershipConfigurationInvalid('Generated partition columns require a reviewed migration.');
        }
        if ($existing !== null && (! $required || ! $existing['nullable'])) {
            return;
        }
        $nullable = $existing['nullable'] ?? true;
        if ($required) {
            $nullable = false;
        }
        $default = $this->scopeDefault($connection, $existing['default'] ?? null);
        $schema->table($table, static function (Blueprint $blueprint) use ($existing, $nullable, $default): void {
            $column = $blueprint->string('owner_scope_key', 191)->nullable($nullable);
            if ($existing !== null) {
                if ($default !== null) {
                    $column->default($default);
                }
                if ($existing['comment'] !== null) {
                    $column->comment($existing['comment']);
                }
                $column->change();
            }
        });
    }

    /** Preserve canonical literal defaults without executing database-supplied SQL expressions. */
    private function scopeDefault(Connection $connection, mixed $default): ?string
    {
        if ($default === null) {
            return null;
        }
        if (is_string($default)) {
            if ($connection->getDriverName() === 'pgsql') {
                if (preg_match('/\ANULL(?:::(?:character varying|text)(?:\([0-9]+\))?)?\z/D', $default) === 1) {
                    return null;
                }
                if (preg_match("/\A'(central|owner:[a-f0-9]{64})'(?:::(?:character varying|text)(?:\([0-9]+\))?)?\z/D", $default, $matches) === 1) {
                    return $matches[1];
                }
            } elseif (preg_match('/\A(?:central|owner:[a-f0-9]{64})\z/D', $default) === 1) {
                return $default;
            }
        }
        throw new OwnershipConfigurationInvalid('Partition defaults require canonical literals or a reviewed migration.');
    }

    /** Recheck physical constraints without treating a marker as sufficient schema evidence. */
    public function assertReady(string $resource): void
    {
        $columnTypes = [];
        $foreignKeys = [];
        $verified = [];
        $this->assertResourceReady($resource, $columnTypes, $foreignKeys, $verified);
    }

    /**
     * Share metadata only inside this live graph inspection, never across admissions.
     *
     * @param  array<string, array<string, string>>  $columnTypes
     * @param  array<string, list<ForeignKey>>  $foreignKeys
     * @param  array<string, true>  $verified
     */
    private function assertResourceReady(string $resource, array &$columnTypes, array &$foreignKeys, array &$verified): void
    {
        if (isset($verified[$resource])) {
            return;
        }
        $definition = $this->resources->get($resource);
        $model = new $definition->model;
        $connection = $model->getConnection();
        $table = $model->getTable();
        $this->constraints->assertProtected($connection, $table);
        $this->assertUnique($connection, $table, [$model->getKeyName(), 'owner_scope_key']);
        $ownership = $definition->ownership;
        if ($ownership->strategy === 'column' || $ownership->strategy === 'pivot') {
            $class = $this->owners->model($ownership->owner ?? throw new OwnershipConfigurationInvalid);
            $owner = new $class;
            $target = $ownership->strategy === 'pivot' ? ($ownership->table ?? throw new OwnershipConfigurationInvalid) : $table;
            $field = $ownership->strategy === 'pivot' ? ($ownership->ownerKey ?? throw new OwnershipConfigurationInvalid) : ($ownership->foreignKey ?? throw new OwnershipConfigurationInvalid);
            $this->assertNativeKey($connection, $target, $field, $owner->getTable(), $owner->getKeyName(), $columnTypes);
            $this->assertForeign($connection, $target, [$field], $owner->getTable(), [$owner->getKeyName()], $foreignKeys);
            if ($ownership->strategy === 'pivot') {
                $resourceKey = $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid;
                $this->constraints->assertProtected($connection, $target, true);
                $this->assertUnique($connection, $target, [$resourceKey]);
                $this->assertNativeKey($connection, $target, $resourceKey, $table, $model->getKeyName(), $columnTypes);
                $this->assertForeign($connection, $target, [$resourceKey], $table, [$model->getKeyName()], $foreignKeys);
            }
        } elseif ($ownership->strategy === 'captured' && ! $connection->getSchemaBuilder()->hasColumn($table, 'owner_reference')) {
            throw new OwnershipSchemaNotReady('Captured storage requires its complete scalar owner reference.');
        } elseif ($ownership->strategy === 'inherited') {
            $relation = $this->parents->relation($definition);
            if (! $relation instanceof MorphTo) {
                $parentClass = $this->resources->get($definition->parents()[0])->model;
                $parent = new $parentClass;
                $this->assertNativeKey($connection, $table, $relation->getForeignKeyName(), $parent->getTable(), $parent->getKeyName(), $columnTypes);
                $this->assertForeign($connection, $table, [$relation->getForeignKeyName()], $parent->getTable(), [$parent->getKeyName()], $foreignKeys);
            }
            foreach ($definition->parents() as $parent) {
                $this->assertResourceReady($parent, $columnTypes, $foreignKeys, $verified);
            }
        }
        $verified[$resource] = true;
    }

    /** @param list<string> $columns */
    private function assertUnique(Connection $connection, string $table, array $columns): void
    {
        foreach ($connection->getSchemaBuilder()->getIndexes($table) as $index) {
            if ($index['unique'] && $index['columns'] === $columns) {
                return;
            }
        }
        throw new OwnershipSchemaNotReady('The canonical primary ownership uniqueness constraint is missing.');
    }

    /**
     * Preserve native types while reading each table's columns once per live inspection.
     *
     * @param  array<string, array<string, string>>|null  $columnTypes
     *
     * @param-out array<string, array<string, string>> $columnTypes
     */
    private function assertNativeKey(Connection $connection, string $table, string $column, string $parent, string $parentColumn, ?array &$columnTypes = null): void
    {
        $columnTypes ??= [];
        foreach ([$table, $parent] as $target) {
            $identity = spl_object_id($connection).':'.$target;
            if (! array_key_exists($identity, $columnTypes)) {
                $columnTypes[$identity] = [];
                foreach ($connection->getSchemaBuilder()->getColumns($target) as $definition) {
                    $columnTypes[$identity][strtolower($definition['name'])] = $definition['type'];
                }
            }
        }
        $native = $columnTypes[spl_object_id($connection).':'.$table][strtolower($column)] ?? null;
        $expected = $columnTypes[spl_object_id($connection).':'.$parent][strtolower($parentColumn)] ?? null;
        if ($native === null || $expected === null || $native !== $expected) {
            throw new OwnershipSchemaNotReady('An ownership foreign key differs from its canonical native key type.');
        }
    }

    /**
     * Add the partition uniqueness unless an equal one exists; a table prefix is part of a new name, which PostgreSQL keeps schema-wide.
     *
     * @param  list<string>  $columns
     */
    private function unique(Connection $connection, string $table, array $columns): void
    {
        $schema = $connection->getSchemaBuilder();
        foreach ($schema->getIndexes($table) as $index) {
            if ($index['columns'] === $columns && $index['unique']) {
                return;
            }
        }
        $name = 'nvl_own_u_'.substr(hash('sha256', $connection->getTablePrefix().$table.json_encode($columns, JSON_THROW_ON_ERROR)), 0, 32);
        $schema->table($table, static fn (Blueprint $table) => $table->unique($columns, $name));
    }

    /** @param list<string> $columns
     * @param  list<string>  $parentColumns
     * @param  array<string, list<ForeignKey>>|null  $foreignKeys
     *
     * @param-out array<string, list<ForeignKey>> $foreignKeys
     */
    private function assertForeign(Connection $connection, string $table, array $columns, string $parent, array $parentColumns, ?array &$foreignKeys = null): void
    {
        $foreignKeys ??= [];
        $identity = spl_object_id($connection).':'.$table;
        $foreignKeys[$identity] ??= $connection->getSchemaBuilder()->getForeignKeys($table);
        foreach ($foreignKeys[$identity] as $foreign) {
            if ($this->references($connection, $foreign, $columns, $parent, $parentColumns)) {
                return;
            }
        }
        throw new OwnershipSchemaNotReady('The host schema has no canonical native owner or parent foreign key.');
    }

    /**
     * Match a native foreign key to its canonical parent, whose table may carry a schema and receives the connection prefix.
     *
     * @param  ForeignKey  $foreign
     * @param  list<string>  $columns
     * @param  list<string>  $parentColumns
     */
    private function references(Connection $connection, array $foreign, array $columns, string $parent, array $parentColumns): bool
    {
        [$schema, $table] = $connection->getSchemaBuilder()->parseSchemaAndTable($parent);

        return $foreign['columns'] === $columns && $foreign['foreign_columns'] === $parentColumns
            && $foreign['foreign_table'] === $connection->getTablePrefix().$table
            && ($schema === null || $foreign['foreign_schema'] === $schema);
    }

    /**
     * Add the composite partition key unless an equal one exists; use the prefix to keep names distinct in a shared schema.
     *
     * @param  list<string>  $columns
     * @param  list<string>  $parentColumns
     */
    private function partitionForeign(Connection $connection, string $table, array $columns, string $parent, array $parentColumns): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }
        $schema = $connection->getSchemaBuilder();
        foreach ($schema->getForeignKeys($table) as $foreign) {
            if ($this->references($connection, $foreign, $columns, $parent, $parentColumns)) {
                return;
            }
        }
        $name = 'nvl_own_f_'.substr(hash('sha256', $connection->getTablePrefix().$table.json_encode($columns, JSON_THROW_ON_ERROR)), 0, 32);
        $schema->table($table, static fn (Blueprint $table) => $table->foreign($columns, $name)->references($parentColumns)->on($parent)->cascadeOnDelete());
    }
}
