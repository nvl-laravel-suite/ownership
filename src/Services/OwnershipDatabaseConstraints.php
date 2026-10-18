<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Connection;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

/** Enforces immutable partitions and primary pivots on every supported database. @internal */
final readonly class OwnershipDatabaseConstraints
{
    /**
     * Apply insert/update validity and immutable fields without rebuilding populated SQLite roots.
     *
     * @param  list<string>  $immutable
     */
    public function protect(Connection $connection, string $table, array $immutable, bool $central, ?string $parentTable = null, ?string $foreignKey = null, ?string $parentKey = null): void
    {
        $wrap = $connection->getQueryGrammar()->wrap(...);
        $scope = 'NEW.'.$wrap('owner_scope_key');
        $valid = match ($connection->getDriverName()) {
            'pgsql' => $scope." ~ '^owner:[a-f0-9]{64}$'",
            'mysql' => "REGEXP_LIKE({$scope}, '^owner:[a-f0-9]{64}$', 'c')",
            'mariadb' => $scope." REGEXP BINARY '^owner:[a-f0-9]{64}$'",
            'sqlite' => 'length('.$scope.") = 70 AND substr({$scope}, 1, 6) = 'owner:' AND substr({$scope}, 7) NOT GLOB '*[^a-f0-9]*'",
            default => throw new OwnershipConfigurationInvalid('Unsupported ownership constraint driver.'),
        };
        if ($central) {
            $valid = '('.$valid.' OR '.$scope." = 'central')";
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
                'mysql', 'mariadb' => 'NOT ('.$old.' <=> '.$new.')',
                default => $old.' IS NOT '.$new,
            };
        }
        $this->trigger($connection, $table, 'update', 'immutable', '('.$invalid.') OR (OLD.'.$wrap('owner_scope_key').' IS NOT NULL AND ('.implode(' OR ', $changes).'))');
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
        foreach (['insert' => 'valid', 'update' => 'immutable', ...($pivot ? ['delete' => 'primary'] : [])] as $event => $purpose) {
            $name = $this->name($table, $event, $purpose);
            $present = match ($connection->getDriverName()) {
                'sqlite' => $connection->selectOne("SELECT name FROM sqlite_master WHERE type = 'trigger' AND name = ?", [$name], false),
                'pgsql' => $connection->selectOne('SELECT tgname FROM pg_trigger WHERE tgname = ? AND tgrelid = ?::regclass AND NOT tgisinternal', [$name, $connection->getTablePrefix().$table], false),
                'mysql', 'mariadb' => $connection->selectOne('SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND TRIGGER_NAME = ? AND EVENT_OBJECT_TABLE = ?', [$name, $connection->getTablePrefix().$table], false),
                default => null,
            };
            if ($present === null) {
                throw new OwnershipSchemaNotReady('An actual ownership immutability constraint is missing.');
            }
        }
    }

    /** Install idempotently only while the coordinator's source-session lease is held. */
    private function trigger(Connection $connection, string $table, string $event, string $purpose, string $condition): void
    {
        $grammar = $connection->getQueryGrammar();
        $name = $grammar->wrap($this->name($table, $event, $purpose));
        $target = $grammar->wrapTable($table);
        $event = strtoupper($event);
        $message = 'NVL ownership partition is invalid or immutable';
        if ($connection->getDriverName() === 'sqlite') {
            $connection->statement("CREATE TRIGGER IF NOT EXISTS {$name} BEFORE {$event} ON {$target} FOR EACH ROW WHEN {$condition} BEGIN SELECT RAISE(ABORT, '{$message}'); END");

            return;
        }
        if ($connection->getDriverName() === 'pgsql') {
            $function = $grammar->wrap($this->name($table, strtolower($event), $purpose).'_fn');
            $result = $event === 'DELETE' ? 'OLD' : 'NEW';
            $connection->statement('CREATE OR REPLACE FUNCTION '.$function.'() RETURNS trigger LANGUAGE plpgsql AS $nvl$ BEGIN IF '.$condition." THEN RAISE EXCEPTION '{$message}'; END IF; RETURN {$result}; END; ".'$nvl$');
            $connection->statement("DROP TRIGGER IF EXISTS {$name} ON {$target}");
            $connection->statement("CREATE TRIGGER {$name} BEFORE {$event} ON {$target} FOR EACH ROW EXECUTE FUNCTION {$function}()");

            return;
        }
        $connection->statement("DROP TRIGGER IF EXISTS {$name}");
        $connection->statement("CREATE TRIGGER {$name} BEFORE {$event} ON {$target} FOR EACH ROW BEGIN IF {$condition} THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = '{$message}'; END IF; END");
    }

    /** Keep protocol identifiers stable and within every supported engine's length limit. */
    private function name(string $table, string $event, string $purpose): string
    {
        return 'nvl_own_'.substr(hash('sha256', $table.'|'.$event.'|'.$purpose), 0, 32);
    }
}
