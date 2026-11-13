<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use PDO;

/** Retains an adopted disposable native schema within one Pest test class. */
final class AdoptedSchemaFixture
{
    private const array ProtocolTables = [
        Tables::Stores, Tables::Runs, Tables::Checkpoints, Tables::Markers, Tables::Mappings, Tables::Operations,
    ];

    /**
     * @var array{
     *     signature: array{string, string, array<string, mixed>},
     *     seeds: array<string, list<array<string, mixed>>>,
     *     catalog: array{tables: list<string>, delete_order: list<string>, can_delete: bool, sequences: list<string>, has_sqlite_sequence: bool}|null,
     *     pdo: PDO|null,
     * }|null
     */
    private static ?array $retained = null;

    private bool $dirty = false;

    private ?PDO $session = null;

    /** @var array<string, mixed> */
    private array $observedConfiguration = [];

    /**
     * @param  array{string, string, array<string, mixed>}  $signature
     * @param  array<string, list<array<string, mixed>>>  $seeds
     * @param  array{tables: list<string>, delete_order: list<string>, can_delete: bool, sequences: list<string>, has_sqlite_sequence: bool}|null  $catalog
     */
    private function __construct(
        private readonly Connection $source,
        private readonly ?Connection $remote,
        private readonly string $driver,
        private readonly array $signature,
        public readonly bool $reused,
        private array $seeds,
        private ?array $catalog,
    ) {}

    public static function start(string $testClass, bool $enabled): ?self
    {
        $retained = self::$retained;
        self::$retained = null;
        if (! $enabled || getenv('NVL_REUSE_ADOPTED_SCHEMA') === '0') {
            return null;
        }

        $configuredDriver = getenv('NVL_OWNERSHIP_TEST_DRIVER');
        $driver = ($configuredDriver === false || $configuredDriver === '') ? 'sqlite' : $configuredDriver;
        if (! in_array($driver, ['sqlite', 'pgsql'], true)) {
            return null;
        }

        $source = DB::connection();
        if ($source->getDriverName() !== $driver || $source->getName() !== 'sqlite' || $source->getTablePrefix() !== '') {
            return null;
        }

        if ($driver === 'pgsql') {
            if ($source->getDatabaseName() !== getenv('NVL_OWNERSHIP_TEST_DATABASE')
                || preg_match('/^nvl_own_test_[a-z_]+_[a-f0-9]{8}_primary$/D', $source->getDatabaseName()) !== 1) {
                return null;
            }
        } elseif ($source->getDatabaseName() !== ':memory:' || config('database.connections.ownership_remote') !== null) {
            return null;
        }

        $remote = config('database.connections.ownership_remote') === null ? null : DB::connection('ownership_remote');
        $signature = [$testClass, $driver, self::configuration()];
        $reused = $retained !== null
            && $retained['signature'] === $signature
            && ($driver !== 'sqlite' || $retained['pdo'] instanceof PDO);

        if ($reused && $driver === 'sqlite' && $retained['pdo'] instanceof PDO) {
            $source->setPdo($retained['pdo'])->setReadPdo($retained['pdo']);
        }

        $instance = new self(
            $source,
            $remote,
            $driver,
            $signature,
            $reused,
            $reused ? $retained['seeds'] : [],
            $reused ? $retained['catalog'] : null,
        );

        if ($reused) {
            $source->beforeExecuting(static function (string $query) use ($instance): void {
                if ($instance->session === null && preg_match('/\A\s*(?:create|alter|drop|rename|truncate)\b/i', $query) === 1) {
                    $instance->dirty = true;
                }
            });
        }

        return $instance;
    }

    public static function forget(): void
    {
        self::$retained = null;
    }

    /** @param list<string> $seedTables Tables with fixed fixture rows, in insertion order. */
    public function observe(array $seedTables = []): void
    {
        if ($this->session !== null) {
            return;
        }

        foreach ($seedTables as $table) {
            if ($this->reused) {
                $this->source->table($table)->insert($this->seeds[$table]);
            } else {
                $rows = [];
                foreach ($this->source->table($table)->useWritePdo()->get()->all() as $row) {
                    $mapped = [];
                    foreach (get_object_vars($row) as $column => $value) {
                        if (is_string($column)) {
                            $mapped[$column] = $value;
                        }
                    }
                    $rows[] = $mapped;
                }
                $this->seeds[$table] = $rows;
            }
        }

        $this->session = $this->source->getPdo();
        $this->observedConfiguration = self::configuration();
        $this->source->beforeExecuting(function (string $query): void {
            if (preg_match('/\A\s*(?:(?:\/\*.*?\*\/|--[^\r\n]*)\s*)*(?:select|insert|update|delete|replace|merge|with|savepoint|release|rollback)\b/is', $query) !== 1
                || str_contains($query, ';')
                || (preg_match('/\b(?:insert|update|delete|replace|merge)\b/i', $query) === 1
                    && preg_match('/\b(?:nvl_ownership_[a-z_]+|migrations)\b/i', $query) === 1)) {
                $this->dirty = true;
            }
        });
        $this->remote?->beforeExecuting(function (): void {
            $this->dirty = true;
        });
    }

    public function reset(): void
    {
        $connections = DB::getConnections();
        $expectedDatabase = $this->driver === 'pgsql' ? getenv('NVL_OWNERSHIP_TEST_DATABASE') : ':memory:';
        if ($this->session === null || $this->dirty || ($connections['sqlite'] ?? null) !== $this->source
            || ($connections['ownership_remote'] ?? null) !== $this->remote
            || array_diff(array_keys($connections), ['sqlite', 'ownership_remote']) !== []
            || $this->source->getRawPdo() !== $this->session
            || $this->source->getDatabaseName() !== $expectedDatabase
            || $this->source->getTablePrefix() !== ''
            || $this->source->transactionLevel() !== 0 || $this->session->inTransaction() || DB::getDefaultConnection() !== 'sqlite'
            || $this->observedConfiguration !== self::configuration()) {
            return;
        }

        $resetOk = $this->driver === 'pgsql' ? $this->resetPostgres() : $this->resetSqlite();
        if (! $resetOk) {
            return;
        }

        self::$retained = [
            'signature' => $this->signature,
            'seeds' => $this->seeds,
            'catalog' => $this->catalog,
            'pdo' => $this->driver === 'sqlite' ? $this->session : null,
        ];
    }

    private function resetPostgres(): bool
    {
        $pdo = $this->session;
        if ($pdo === null) {
            return false;
        }

        $stateRows = $this->fetchRows(
            $pdo,
            "select current_database() as db, current_schema() as schema, (select array_agg(nspname::text order by nspname) from pg_namespace where nspname not like 'pg_%' and nspname <> 'information_schema') as schemas",
        );
        $state = $stateRows[0] ?? null;

        if ($state === null
            || ($state['db'] ?? null) !== $this->source->getDatabaseName()
            || ($state['schema'] ?? null) !== 'public'
            || ($state['schemas'] ?? null) !== '{public}') {
            return false;
        }

        $this->catalog ??= $this->postgresCatalog($pdo);
        if ($this->catalog['tables'] === []) {
            return false;
        }

        $probes = [];
        foreach ($this->catalog['delete_order'] as $index => $table) {
            $probes[] = "select 't' as k, {$index} as idx where exists (select 1 from ".$this->quotePostgresTable($table).')';
        }
        foreach ($this->catalog['sequences'] as $index => $sequence) {
            $probes[] = "select 's' as k, {$index} as idx from ".$this->quotePostgresTable($sequence).' where is_called or last_value <> 1';
        }

        if ($probes === []) {
            return true;
        }

        $dirtyTables = [];
        $dirtySequences = [];
        foreach ($this->fetchRows($pdo, implode(' union all ', $probes)) as $row) {
            $rawIdx = $row['idx'] ?? null;
            if (! is_int($rawIdx) && ! is_string($rawIdx)) {
                continue;
            }
            $idx = (int) $rawIdx;
            if (($row['k'] ?? null) === 't' && isset($this->catalog['delete_order'][$idx])) {
                $dirtyTables[] = $this->catalog['delete_order'][$idx];
            } elseif (isset($this->catalog['sequences'][$idx])) {
                $dirtySequences[] = $this->catalog['sequences'][$idx];
            }
        }

        if ($dirtyTables === [] && $dirtySequences === []) {
            return true;
        }

        if ($this->catalog['can_delete']) {
            $statements = [];
            foreach ($dirtyTables as $table) {
                $statements[] = 'delete from '.$this->quotePostgresTable($table);
            }
            foreach ($dirtySequences as $sequence) {
                $statements[] = 'select setval('.$pdo->quote($this->quotePostgresTable($sequence)).', 1, false)';
            }

            try {
                $pdo->exec(implode('; ', $statements));

                return true;
            } catch (\Throwable) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }

        $wrapped = array_map($this->quotePostgresTable(...), $this->catalog['tables']);
        $pdo->exec('truncate table '.implode(', ', $wrapped).' restart identity');

        return true;
    }

    private function resetSqlite(): bool
    {
        $pdo = $this->session;
        if ($pdo === null) {
            return false;
        }

        $this->catalog ??= $this->sqliteCatalog($pdo);
        if ($this->catalog['tables'] === []) {
            return false;
        }

        $probes = [];
        foreach ($this->catalog['delete_order'] as $index => $table) {
            $probes[] = "select {$index} as idx where exists (select 1 from ".$this->quoteIdentifier($table).')';
        }

        $dirtyTables = [];
        if ($probes !== []) {
            foreach ($this->fetchRows($pdo, implode(' union all ', $probes)) as $row) {
                $rawIdx = $row['idx'] ?? null;
                if ((is_int($rawIdx) || is_string($rawIdx)) && isset($this->catalog['delete_order'][(int) $rawIdx])) {
                    $dirtyTables[] = $this->catalog['delete_order'][(int) $rawIdx];
                }
            }
        }

        if ($dirtyTables === [] && ! $this->catalog['has_sqlite_sequence']) {
            return true;
        }

        $fkStatement = $pdo->query('pragma foreign_keys');
        if ($fkStatement === false) {
            return false;
        }
        $fkValue = $fkStatement->fetchColumn();
        $foreignKeys = is_int($fkValue) || (is_string($fkValue) && ctype_digit($fkValue)) ? (int) $fkValue : 0;

        try {
            $pdo->exec('pragma foreign_keys = 0');
            foreach ($dirtyTables as $table) {
                $pdo->exec('delete from '.$this->quoteIdentifier($table));
            }
            if ($this->catalog['has_sqlite_sequence']) {
                $excluded = implode(', ', array_map($pdo->quote(...), self::ProtocolTables));
                $pdo->exec("delete from \"sqlite_sequence\" where \"name\" not in ({$excluded})");
            }
        } catch (\Throwable) {
            return false;
        } finally {
            try {
                $pdo->exec('pragma foreign_keys = '.$foreignKeys);
            } catch (\Throwable) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{tables: list<string>, delete_order: list<string>, can_delete: bool, sequences: list<string>, has_sqlite_sequence: bool}
     */
    private function postgresCatalog(PDO $pdo): array
    {
        $excluded = array_fill_keys(self::ProtocolTables, true);
        $tables = [];
        $hasDeleteTrigger = [];

        $tableRows = $this->fetchRows(
            $pdo,
            "select c.relname as name, exists (select 1 from pg_trigger t where t.tgrelid = c.oid and not t.tgisinternal and (t.tgtype & 8) <> 0) as has_delete_trigger from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = 'public' and c.relkind in ('r', 'p') order by c.relname",
        );

        foreach ($tableRows as $row) {
            $name = $row['name'] ?? null;
            if (! is_string($name) || isset($excluded[$name])) {
                continue;
            }
            $tables[] = $name;
            $hasDeleteTrigger[$name] = (bool) ($row['has_delete_trigger'] ?? false);
        }

        $tableSet = array_fill_keys($tables, true);
        $edges = array_fill_keys($tables, []);
        $inDegree = array_fill_keys($tables, 0);

        $fkRows = $this->fetchRows(
            $pdo,
            "select child.relname as child_table, parent.relname as parent_table, con.confdeltype as del_type from pg_constraint con join pg_class child on child.oid = con.conrelid join pg_namespace cn on cn.oid = child.relnamespace join pg_class parent on parent.oid = con.confrelid join pg_namespace pn on pn.oid = parent.relnamespace where con.contype = 'f' and cn.nspname = 'public' and pn.nspname = 'public' and child.oid <> parent.oid",
        );

        foreach ($fkRows as $row) {
            $child = $row['child_table'] ?? null;
            $parent = $row['parent_table'] ?? null;
            if (! is_string($child) || ! is_string($parent) || ! isset($tableSet[$child], $tableSet[$parent])) {
                continue;
            }
            $first = ($hasDeleteTrigger[$child] && ($row['del_type'] ?? null) === 'c') ? $parent : $child;
            $second = $first === $parent ? $child : $parent;
            if (! isset($edges[$first][$second])) {
                $edges[$first][$second] = true;
                $inDegree[$second]++;
            }
        }

        $queue = [];
        foreach ($tables as $table) {
            if ($inDegree[$table] === 0) {
                $queue[] = $table;
            }
        }

        $deleteOrder = [];
        while ($queue !== []) {
            $current = array_shift($queue);
            $deleteOrder[] = $current;
            foreach (array_keys($edges[$current]) as $next) {
                $inDegree[$next]--;
                if ($inDegree[$next] === 0) {
                    $queue[] = $next;
                }
            }
        }

        $canDelete = count($deleteOrder) === count($tables);
        if (! $canDelete) {
            $deleteOrder = $tables;
        }

        $sequences = [];
        $seqRows = $this->fetchRows(
            $pdo,
            "select distinct s.relname as name, tbl.relname as owner_table from pg_class s join pg_namespace sn on sn.oid = s.relnamespace left join pg_depend d on d.classid = 'pg_class'::regclass and d.objid = s.oid and d.refclassid = 'pg_class'::regclass and d.deptype in ('a', 'i') left join pg_class tbl on tbl.oid = d.refobjid where sn.nspname = 'public' and s.relkind = 'S' order by s.relname",
        );

        foreach ($seqRows as $row) {
            $seqName = $row['name'] ?? null;
            if (! is_string($seqName)) {
                continue;
            }
            $owner = is_string($row['owner_table'] ?? null) ? $row['owner_table'] : null;
            if ($owner !== null && isset($excluded[$owner])) {
                continue;
            }
            $sequences[] = $seqName;
        }

        return [
            'tables' => $tables,
            'delete_order' => $deleteOrder,
            'can_delete' => $canDelete,
            'sequences' => array_values(array_unique($sequences)),
            'has_sqlite_sequence' => false,
        ];
    }

    /**
     * @return array{tables: list<string>, delete_order: list<string>, can_delete: bool, sequences: list<string>, has_sqlite_sequence: bool}
     */
    private function sqliteCatalog(PDO $pdo): array
    {
        $excluded = array_fill_keys(self::ProtocolTables, true);
        $tables = [];
        $triggerTables = [];
        $hasSqliteSequence = false;

        $rows = $this->fetchRows($pdo, "select type, name, tbl_name, sql from sqlite_master where type in ('table', 'trigger')");
        foreach ($rows as $row) {
            $name = $row['name'] ?? null;
            if (! is_string($name)) {
                continue;
            }
            if (($row['type'] ?? null) === 'table') {
                if ($name === 'sqlite_sequence') {
                    $hasSqliteSequence = true;

                    continue;
                }
                if (str_starts_with($name, 'sqlite_') || isset($excluded[$name])) {
                    continue;
                }
                $tables[] = $name;
            } elseif (is_string($row['sql'] ?? null) && is_string($row['tbl_name'] ?? null) && stripos($row['sql'], 'before delete') !== false) {
                $triggerTables[$row['tbl_name']] = true;
            }
        }

        sort($tables);
        $first = [];
        $last = [];
        foreach ($tables as $table) {
            if (isset($triggerTables[$table])) {
                $last[] = $table;
            } else {
                $first[] = $table;
            }
        }

        return [
            'tables' => $tables,
            'delete_order' => [...$first, ...$last],
            'can_delete' => true,
            'sequences' => [],
            'has_sqlite_sequence' => $hasSqliteSequence,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function fetchRows(PDO $pdo, string $sql): array
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            return [];
        }

        $result = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (! is_array($row)) {
                continue;
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                if (is_string($key)) {
                    $normalized[$key] = $value;
                }
            }
            $result[] = $normalized;
        }

        return $result;
    }

    private function quotePostgresTable(string $identifier): string
    {
        return '"public".'.$this->quoteIdentifier($identifier);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '"'.str_replace('"', '""', $identifier).'"';
    }

    /** @return array<string, mixed> */
    private static function configuration(): array
    {
        return array_filter(config()->all(), static fn (string $key): bool => in_array($key, ['database', 'ownership-test'], true) || str_starts_with($key, 'nvl-'), ARRAY_FILTER_USE_KEY);
    }
}
