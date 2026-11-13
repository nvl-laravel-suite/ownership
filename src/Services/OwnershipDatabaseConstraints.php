<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Connection;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;

/**
 * Enforces immutable partitions and primary pivots on every supported database.
 *
 * @internal
 */
final readonly class OwnershipDatabaseConstraints
{
    /** Inspect canonical protocol constraint identities. */
    public function __construct(private OwnershipConstraintInspector $inspector) {}

    /**
     * Apply insert/update validity and immutable fields without rebuilding populated SQLite roots.
     *
     * @param  list<string>  $immutable
     */
    public function protect(Connection $connection, string $table, array $immutable, bool $central, ?string $parentTable = null, ?string $foreignKey = null, ?string $parentKey = null): void
    {
        $wrap = $connection->getQueryGrammar()->wrap(...);
        $scope = 'NEW.'.$wrap('owner_scope_key');
        $driver = $connection->getDriverName();
        $valid = match ($driver) {
            'pgsql' => $scope." ~ '^owner:[a-f0-9]{64}$'",
            'sqlite' => 'length('.$scope.") = 70 AND substr({$scope}, 1, 6) = 'owner:' AND substr({$scope}, 7) NOT GLOB '*[^a-f0-9]*'",
            default => throw new OwnershipConfigurationInvalid('Unsupported ownership constraint driver.'),
        };
        if ($driver !== 'sqlite') {
            $valid = 'length('.$scope.') = 70 AND ('.$valid.')';
        }
        if ($central) {
            $valid = "({$valid} OR (length({$scope}) = 7 AND {$scope} = 'central'))";
        }
        $invalid = $scope.' IS NULL OR NOT ('.$valid.')';
        if ($parentTable !== null && $foreignKey !== null && $parentKey !== null) {
            $parent = $connection->getQueryGrammar()->wrapTable($parentTable);
            $invalid .= ' OR NOT EXISTS (SELECT 1 FROM '.$parent.' WHERE '.$wrap($parentKey).' = NEW.'.$wrap($foreignKey).' AND '.$wrap('owner_scope_key').' = '.$scope.')';
        }
        $this->trigger($connection, $table, 'insert', 'valid', $invalid);
        $changes = [];
        foreach ($immutable as $column) {
            $old = 'OLD.'.$wrap($column);
            $new = 'NEW.'.$wrap($column);
            if ($connection->getDriverName() === 'pgsql' && $column === 'owner_reference') {
                $old .= '::jsonb';
                $new .= '::jsonb';
            }
            $changes[] = match ($connection->getDriverName()) {
                'pgsql' => $old.' IS DISTINCT FROM '.$new,
                'sqlite' => $old.' IS NOT '.$new,
                default => throw new OwnershipConfigurationInvalid('Unsupported ownership constraint driver.'),
            };
        }
        $this->trigger($connection, $table, 'update', 'immutable', '('.$invalid.') OR (OLD.'.$wrap('owner_scope_key').' IS NOT NULL AND ('.implode(' OR ', $changes).'))');
    }

    /** Freeze durable scalar/JSON fields, optionally after publication or allowing terminal erasure.
     * @param  non-empty-list<string>  $fields
     * @param  list<string>  $afterStatuses
     * @param  list<string>  $eraseStatuses
     */
    public function freeze(Connection $connection, string $table, array $fields, string $statusColumn = 'status', array $afterStatuses = [], array $eraseStatuses = [], bool $retainStatuses = false): void
    {
        $grammar = $connection->getQueryGrammar();
        $changes = [];
        foreach ($fields as $column) {
            $old = 'OLD.'.$grammar->wrap($column);
            $new = 'NEW.'.$grammar->wrap($column);
            $different = match ($connection->getDriverName()) {
                'pgsql' => 'to_jsonb('.$old.') IS DISTINCT FROM to_jsonb('.$new.')',
                'sqlite' => $old.' IS NOT '.$new,
                default => throw new OwnershipConfigurationInvalid('Unsupported ownership constraint driver.'),
            };
            if ($eraseStatuses !== []) {
                $statuses = implode(',', array_map(fn (string $v): string => $connection->getPdo()->quote($v), $eraseStatuses));
                $different = '('.$different.') AND NOT ('.$new.' IS NULL AND NEW.'.$grammar->wrap($statusColumn).' IN ('.$statuses.'))';
            }
            $changes[] = '('.$different.')';
        }
        $condition = implode(' OR ', $changes);
        if ($afterStatuses !== []) {
            $statuses = implode(',', array_map(fn (string $v): string => $connection->getPdo()->quote($v), $afterStatuses));
            if ($retainStatuses) {
                $condition .= ' OR NEW.'.$grammar->wrap($statusColumn).' NOT IN ('.$statuses.')';
            }
            $condition = 'OLD.'.$grammar->wrap($statusColumn).' IN ('.$statuses.') AND ('.$condition.')';
        }
        $this->trigger($connection, $table, 'update', 'freeze:'.hash('sha256', json_encode([$fields, $statusColumn, $afterStatuses, $eraseStatuses, $retainStatuses], JSON_THROW_ON_ERROR)), $condition);
    }

    /** Freeze each assigned external identity while allowing its first provider assignment.
     * @param  list<string>  $afterStatuses
     * @param  non-empty-list<string>  $fields
     */
    public function freezeAssigned(Connection $connection, string $table, array $fields, ?string $statusColumn = null, array $afterStatuses = []): void
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = 'OLD.'.$connection->getQueryGrammar()->wrap($field);
            $new = 'NEW.'.$connection->getQueryGrammar()->wrap($field);
            $different = match ($connection->getDriverName()) {
                'pgsql' => $old.' IS DISTINCT FROM '.$new,
                'sqlite' => $old.' IS NOT '.$new,
                default => throw new OwnershipConfigurationInvalid('Unsupported ownership constraint driver.'),
            };
            $changes[] = '('.$old.' IS NOT NULL AND ('.$different.'))';
        }
        $condition = implode(' OR ', $changes);
        $purpose = 'assigned:'.hash('sha256', json_encode($fields, JSON_THROW_ON_ERROR));
        if ($statusColumn !== null && $afterStatuses !== []) {
            $statuses = implode(',', array_map(fn (string $status): string => $connection->getPdo()->quote($status), $afterStatuses));
            $condition = 'OLD.'.$connection->getQueryGrammar()->wrap($statusColumn).' IN ('.$statuses.') AND ('.$condition.')';
            $purpose .= ':'.hash('sha256', json_encode([$statusColumn, $afterStatuses], JSON_THROW_ON_ERROR));
        } elseif ($statusColumn !== null || $afterStatuses !== []) {
            throw new OwnershipConfigurationInvalid('Conditional assigned identities require both a status column and statuses.');
        }
        $this->trigger($connection, $table, 'update', $purpose, $condition);
    }

    /** Enforce an additional concrete reference in the same native owner partition.
     * @param  list<string>  $sameColumns
     */
    public function reference(Connection $connection, string $table, string $foreignKey, string $parentTable, string $parentKey, bool $nullable = false, array $sameColumns = []): void
    {
        $grammar = $connection->getQueryGrammar();
        $foreign = 'NEW.'.$grammar->wrap($foreignKey);
        $missing = 'NOT EXISTS (SELECT 1 FROM '.$grammar->wrapTable($parentTable).' WHERE '.$grammar->wrap($parentKey).' = '.$foreign.' AND '.$grammar->wrap('owner_scope_key').' = NEW.'.$grammar->wrap('owner_scope_key').')';
        foreach ($sameColumns as $column) {
            $missing = substr($missing, 0, -1).' AND '.$grammar->wrap($column).' = NEW.'.$grammar->wrap($column).')';
        }
        $invalid = $nullable ? $foreign.' IS NOT NULL AND '.$missing : $foreign.' IS NULL OR '.$missing;
        foreach (['insert', 'update'] as $event) {
            $this->trigger($connection, $table, $event, $this->referencePurpose($foreignKey, $parentTable, $parentKey), $invalid);
        }
    }

    /** Require each live secondary-reference trigger on the actual physical source. */
    public function assertReference(Connection $connection, string $table, string $foreignKey, string $parentTable, string $parentKey): void
    {
        [$namespace, $logicalTable] = $connection->getSchemaBuilder()->parseSchemaAndTable($table, true);
        $physicalTable = $connection->getTablePrefix().$logicalTable;
        $catalog = $connection->getQueryGrammar()->wrap($namespace ?? 'main').'.sqlite_master';
        foreach (['insert', 'update'] as $event) {
            $name = $this->name($connection, $table, $event, $this->referencePurpose($foreignKey, $parentTable, $parentKey));
            $present = match ($connection->getDriverName()) {
                'sqlite' => $connection->selectOne("SELECT name FROM {$catalog} WHERE type = 'trigger' AND tbl_name = ? AND name = ?", [$physicalTable, $name], false),
                'pgsql' => $connection->selectOne('SELECT tgname FROM pg_trigger WHERE tgname = ? AND tgrelid = to_regclass(?) AND NOT tgisinternal', [$name, $connection->getQueryGrammar()->wrapTable($table)], false),
                default => null,
            };
            if ($present === null) {
                throw new OwnershipSchemaNotReady('A native ownership reference constraint is missing.');
            }
        }
    }

    /** Enforce the closed native morph allowlist and exact textual keys in the same owner partition.
     * @param  list<array{type:string,table:string,key:string}>  $targets
     */
    public function polymorphicReference(Connection $connection, string $table, string $typeColumn, string $keyColumn, array $targets): void
    {
        $grammar = $connection->getQueryGrammar();
        $type = 'NEW.'.$grammar->wrap($typeColumn);
        $key = 'NEW.'.$grammar->wrap($keyColumn);
        $driver = $connection->getDriverName();
        $valid = [];
        foreach ($targets as $target) {
            $native = $grammar->wrap($target['key']);
            $text = match ($driver) {
                'pgsql' => '(CAST('.$native.' AS TEXT) COLLATE "C") = ('.$key.' COLLATE "C")',
                'sqlite' => '(CAST('.$native.' AS TEXT) COLLATE BINARY) = ('.$key.' COLLATE BINARY)',
                default => throw new OwnershipConfigurationInvalid('Unsupported polymorphic ownership constraint driver.'),
            };
            $morph = $connection->getPdo()->quote($target['type']);
            $valid[] = '('.$type.' = '.$morph.' AND EXISTS (SELECT 1 FROM '.$grammar->wrapTable($target['table']).' WHERE '.$text.' AND '.$grammar->wrap('owner_scope_key').' = NEW.'.$grammar->wrap('owner_scope_key').'))';
        }
        $invalid = $type.' IS NULL OR '.$key.' IS NULL OR NOT ('.($valid === [] ? '0=1' : implode(' OR ', $valid)).')';
        $existing = str_replace('NEW.', $grammar->wrap('nvl_existing').'.', $invalid);
        if ($connection->selectOne('SELECT 1 FROM '.$grammar->wrapTable($table).' AS '.$grammar->wrap('nvl_existing').' WHERE '.$existing.' LIMIT 1', [], false) !== null) {
            throw new OwnershipConfigurationInvalid('Existing polymorphic references cross their canonical ownership boundary.');
        }
        foreach (['insert', 'update'] as $event) {
            $this->trigger($connection, $table, $event, 'polymorphic:'.$typeColumn.':'.$keyColumn, $invalid);
        }
    }

    /** Retain a separate stable identity for each package-declared concrete reference. */
    private function referencePurpose(string $foreignKey, string $parentTable, string $parentKey): string
    {
        return 'reference:'.$foreignKey.':'.$parentTable.':'.$parentKey;
    }

    /** Reject pivot detach while its owning root still exists; root deletion retains native cascade behavior. */
    public function protectPivotDelete(Connection $connection, string $table, string $resourceKey, string $rootTable, string $rootKey): void
    {
        $grammar = $connection->getQueryGrammar();
        $condition = 'EXISTS (SELECT 1 FROM '.$grammar->wrapTable($rootTable).' WHERE '.$grammar->wrap($rootKey).' = OLD.'.$grammar->wrap($resourceKey).')';
        $this->trigger($connection, $table, 'delete', 'primary', $condition);
    }

    /** Confirm every required live database trigger exists on the actual source. */
    public function assertProtected(Connection $connection, string $table, bool $pivot = false): void
    {
        $required = [];
        foreach (['insert' => 'valid', 'update' => 'immutable', ...($pivot ? ['delete' => 'primary'] : [])] as $event => $purpose) {
            $required[$this->name($connection, $table, $event, $purpose)] = $event.':'.$purpose;
        }
        $placeholders = implode(', ', array_fill(0, count($required), '?'));
        $names = array_keys($required);
        [$namespace, $logicalTable] = $connection->getSchemaBuilder()->parseSchemaAndTable($table, true);
        $physicalTable = $connection->getTablePrefix().$logicalTable;
        $catalog = $connection->getQueryGrammar()->wrap($namespace ?? 'main').'.sqlite_master';
        $rows = match ($connection->getDriverName()) {
            'sqlite' => $connection->select("SELECT name FROM {$catalog} WHERE type = 'trigger' AND tbl_name = ? AND name IN ({$placeholders})", [$physicalTable, ...$names], false),
            'pgsql' => $connection->select("SELECT tgname AS name FROM pg_trigger WHERE tgrelid = to_regclass(?) AND NOT tgisinternal AND tgname IN ({$placeholders})", [$connection->getQueryGrammar()->wrapTable($table), ...$names], false),
            default => [],
        };
        $present = [];
        foreach ($rows as $row) {
            $name = is_object($row) ? ($row->name ?? null) : null;
            if (! is_string($name)) {
                throw new OwnershipSchemaNotReady('The native ownership trigger catalog returned an invalid name.');
            }
            $present[$name] = true;
        }
        foreach ($required as $name => $identity) {
            if (! isset($present[$name])) {
                throw new OwnershipSchemaNotReady('An actual ownership immutability constraint is missing for ['.$table.'] ['.$identity.'].');
            }
        }
    }

    /** Replace any installed definition, only while the coordinator's source-session lease is held. */
    private function trigger(Connection $connection, string $table, string $event, string $purpose, string $condition): void
    {
        $grammar = $connection->getQueryGrammar();
        $name = $grammar->wrap($this->name($connection, $table, $event, $purpose));
        $target = $grammar->wrapTable($table);
        $event = strtoupper($event);
        $message = 'NVL ownership partition is invalid or immutable';
        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement("DROP TRIGGER IF EXISTS {$name}");
            $connection->statement("CREATE TRIGGER {$name} BEFORE {$event} ON {$target} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END");

            return;
        }
        if ($connection->getDriverName() === 'pgsql') {
            $function = $grammar->wrap($this->name($connection, $table, strtolower($event), $purpose).'_fn');
            $result = $event === 'DELETE' ? 'OLD' : 'NEW';
            $connection->statement('CREATE OR REPLACE FUNCTION '.$function.'() RETURNS trigger LANGUAGE plpgsql AS $nvl$ BEGIN IF '.$condition." THEN RAISE EXCEPTION '{$message}'; END IF; RETURN {$result}; END; ".'$nvl$');
            $connection->statement("DROP TRIGGER IF EXISTS {$name} ON {$target}");
            $connection->statement("CREATE TRIGGER {$name} BEFORE {$event} ON {$target} FOR EACH ROW EXECUTE FUNCTION {$function}()");

            return;
        }
        throw new OwnershipConfigurationInvalid('Unsupported ownership constraint driver.');
    }

    /** Keep protocol identifiers stable and within every supported engine's length limit. */
    private function name(Connection $connection, string $table, string $event, string $purpose): string
    {
        return $this->inspector->name($connection, $table, $event, $purpose);
    }
}
