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

/** Shared partition DDL invoked explicitly by each package's adoption adapter. @internal */
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
        if (! $schema->hasColumn($table, 'owner_scope_key')) {
            $schema->table($table, static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->nullable());
        }
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
            if (! $schema->hasColumn($pivot, 'owner_scope_key')) {
                $schema->table($pivot, static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->nullable());
            }
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
            $connection->getSchemaBuilder()->table($model->getTable(), static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->nullable(false)->change());
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
                $connection->getSchemaBuilder()->table($pivot, static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->nullable(false)->change());
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

    /** Recheck physical constraints without treating a marker as sufficient schema evidence. */
    public function assertReady(string $resource): void
    {
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
            $this->assertNativeKey($connection, $target, $field, $owner->getTable(), $owner->getKeyName());
            $this->assertForeign($connection, $target, [$field], $owner->getTable(), [$owner->getKeyName()]);
            if ($ownership->strategy === 'pivot') {
                $resourceKey = $ownership->resourceKey ?? throw new OwnershipConfigurationInvalid;
                $this->constraints->assertProtected($connection, $target, true);
                $this->assertUnique($connection, $target, [$resourceKey]);
                $this->assertNativeKey($connection, $target, $resourceKey, $table, $model->getKeyName());
                $this->assertForeign($connection, $target, [$resourceKey], $table, [$model->getKeyName()]);
            }
        } elseif ($ownership->strategy === 'captured' && ! $connection->getSchemaBuilder()->hasColumn($table, 'owner_reference')) {
            throw new OwnershipSchemaNotReady('Captured storage requires its complete scalar owner reference.');
        } elseif ($ownership->strategy === 'inherited') {
            $relation = $this->parents->relation($definition);
            if (! $relation instanceof MorphTo) {
                $parentClass = $this->resources->get($definition->parents()[0])->model;
                $parent = new $parentClass;
                $this->assertNativeKey($connection, $table, $relation->getForeignKeyName(), $parent->getTable(), $parent->getKeyName());
                $this->assertForeign($connection, $table, [$relation->getForeignKeyName()], $parent->getTable(), [$parent->getKeyName()]);
            }
            foreach ($definition->parents() as $parent) {
                $this->assertReady($parent);
            }
        }
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

    /** Preserve the owner's exact native key storage rather than relying on driver coercion. */
    private function assertNativeKey(Connection $connection, string $table, string $column, string $parent, string $parentColumn): void
    {
        $schema = $connection->getSchemaBuilder();
        if ($schema->getColumnType($table, $column, true) !== $schema->getColumnType($parent, $parentColumn, true)) {
            throw new OwnershipSchemaNotReady('An ownership foreign key differs from its canonical native key type.');
        }
    }

    /** @param list<string> $columns */
    private function unique(Connection $connection, string $table, array $columns): void
    {
        $schema = $connection->getSchemaBuilder();
        foreach ($schema->getIndexes($table) as $index) {
            if ($index['columns'] === $columns && $index['unique']) {
                return;
            }
        }
        $name = 'nvl_own_u_'.substr(hash('sha256', $table.json_encode($columns, JSON_THROW_ON_ERROR)), 0, 32);
        $schema->table($table, static fn (Blueprint $table) => $table->unique($columns, $name));
    }

    /** @param list<string> $columns
     * @param  list<string>  $parentColumns
     */
    private function assertForeign(Connection $connection, string $table, array $columns, string $parent, array $parentColumns): void
    {
        foreach ($connection->getSchemaBuilder()->getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === $columns && $foreign['foreign_table'] === $connection->getTablePrefix().$parent && $foreign['foreign_columns'] === $parentColumns) {
                return;
            }
        }
        throw new OwnershipSchemaNotReady('The host schema has no canonical native owner or parent foreign key.');
    }

    /** @param list<string> $columns
     * @param  list<string>  $parentColumns
     */
    private function partitionForeign(Connection $connection, string $table, array $columns, string $parent, array $parentColumns): void
    {
        if ($connection->getDriverName() === 'sqlite') {
            return;
        }
        $schema = $connection->getSchemaBuilder();
        foreach ($schema->getForeignKeys($table) as $foreign) {
            if ($foreign['columns'] === $columns && $foreign['foreign_table'] === $parent && $foreign['foreign_columns'] === $parentColumns) {
                return;
            }
        }
        $name = 'nvl_own_f_'.substr(hash('sha256', $table.json_encode($columns, JSON_THROW_ON_ERROR)), 0, 32);
        $schema->table($table, static fn (Blueprint $table) => $table->foreign($columns, $name)->references($parentColumns)->on($parent)->cascadeOnDelete());
    }
}
