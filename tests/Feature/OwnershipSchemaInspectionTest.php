<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Database\Schema\Grammars\Grammar;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
});

it('bounds native metadata reads within each pivot schema inspection', function (): void {
    $connection = DB::connection();
    $maximumQueries = $connection->getDriverName() === 'sqlite' ? 11 : 8;
    if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        foreach (['ownership_test_pivot_assets', 'ownership_test_asset_owners', 'ownership_test_child_assets'] as $table) {
            $column = array_find($connection->getSchemaBuilder()->getColumns($table), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
            expect($column['collation'])->toBe('ascii_bin');
        }
    }
    $connection->enableQueryLog();
    try {
        foreach ([1, 2] as $inspection) {
            $connection->flushQueryLog();
            app(OwnershipSchema::class)->assertReady('assets.pivot');

            $queries = $connection->getQueryLog();
            $this->assertLessThanOrEqual($maximumQueries, count($queries), implode("\n", array_column($queries, 'query')));
        }
    } finally {
        $connection->disableQueryLog();
    }
});

it('does not retain schema readiness after a live protection is removed', function (): void {
    $schema = app(OwnershipSchema::class);
    $schema->assertReady('assets.pivot');
    $connection = DB::connection();
    $name = 'nvl_own_'.substr(hash('sha256', 'ownership_test_pivot_assets|insert|valid'), 0, 32);
    $statement = 'DROP TRIGGER '.$connection->getQueryGrammar()->wrap($name);
    if ($connection->getDriverName() === 'pgsql') {
        $statement .= ' ON ownership_test_pivot_assets';
    }
    $connection->statement($statement);

    expect(fn () => $schema->assertReady('assets.pivot'))->toThrow(OwnershipSchemaNotReady::class);
});

it('recognizes an existing partition foreign key on a prefixed connection instead of adding it again', function (): void {
    $schema = Mockery::mock(Builder::class);
    $schema->shouldReceive('getForeignKeys')->once()->with('assets')->andReturn([[
        'name' => 'nvl_own_f_existing', 'columns' => ['company_id', 'owner_scope_key'], 'foreign_schema' => null,
        'foreign_table' => 'app_companies', 'foreign_columns' => ['id', 'owner_scope_key'], 'on_update' => 'no action', 'on_delete' => 'cascade',
    ]]);
    $schema->shouldNotReceive('table');
    $schema->shouldReceive('parseSchemaAndTable')->passthru();
    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('getDriverName')->andReturn('mysql');
    $connection->shouldReceive('getTablePrefix')->andReturn('app_');
    $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);

    (new ReflectionMethod(OwnershipSchema::class, 'partitionForeign'))->invoke(app(OwnershipSchema::class), $connection, 'assets', ['company_id', 'owner_scope_key'], 'companies', ['id', 'owner_scope_key']);
});

it('includes a table prefix in newly created partition constraint names and leaves unprefixed names unchanged', function (string $method, string $legacy): void {
    $name = static function (string $prefix) use ($method): string {
        $names = [];
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('getDriverName')->andReturn('mysql');
        $connection->shouldReceive('getTablePrefix')->andReturn($prefix);
        $connection->shouldReceive('getSchemaGrammar')->andReturn(Mockery::mock(Grammar::class));
        $schema = Mockery::mock(Builder::class);
        $schema->shouldReceive('getForeignKeys')->andReturn([]);
        $schema->shouldReceive('getIndexes')->andReturn([]);
        $schema->shouldReceive('parseSchemaAndTable')->passthru();
        $schema->shouldReceive('table')->once()->andReturnUsing(static function (string $table, Closure $callback) use ($connection, &$names): void {
            $blueprint = new Blueprint($connection, $table);
            $callback($blueprint);
            $names[] = $blueprint->getCommands()[0]->index;
        });
        $connection->shouldReceive('getSchemaBuilder')->andReturn($schema);
        $arguments = $method === 'partitionForeign'
            ? [$connection, 'assets', ['company_id', 'owner_scope_key'], 'companies', ['id', 'owner_scope_key']]
            : [$connection, 'assets', ['id', 'owner_scope_key']];
        (new ReflectionMethod(OwnershipSchema::class, $method))->invoke(app(OwnershipSchema::class), ...$arguments);

        return $names[0];
    };

    expect($name(''))->toBe($legacy)
        ->and($name('a_'))->not->toBe($legacy)
        ->and($name('a_'))->not->toBe($name('b_'))
        ->and($name('a_'))->toBe($name('a_'))
        ->and(strlen($name('a_')))->toBe(strlen($legacy));
})->with([
    'partition foreign key' => ['partitionForeign', 'nvl_own_f_'.substr(hash('sha256', 'assets["company_id","owner_scope_key"]'), 0, 32)],
    'partition unique key' => ['unique', 'nvl_own_u_'.substr(hash('sha256', 'assets["id","owner_scope_key"]'), 0, 32)],
]);

it('adds partition constraints for the same logical table under two prefixes of one native database', function (): void {
    $base = DB::connection();
    if ($base->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite names neither partition foreign keys nor schema-wide indexes.');
    }
    $schema = app(OwnershipSchema::class);
    foreach (['a_', 'b_'] as $prefix) {
        config(['database.connections.prefixed_'.$prefix => [...$base->getConfig(), 'prefix' => $prefix, 'name' => 'prefixed_'.$prefix]]);
        $connection = DB::connection('prefixed_'.$prefix);
        $connection->getSchemaBuilder()->create('prefix_parents', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('owner_scope_key', 191)->nullable();
        });
        $connection->getSchemaBuilder()->create('prefix_children', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('parent_id');
            $table->string('owner_scope_key', 191)->nullable();
        });
        (new ReflectionMethod(OwnershipSchema::class, 'unique'))->invoke($schema, $connection, 'prefix_parents', ['id', 'owner_scope_key']);
        (new ReflectionMethod(OwnershipSchema::class, 'partitionForeign'))->invoke($schema, $connection, 'prefix_children', ['parent_id', 'owner_scope_key'], 'prefix_parents', ['id', 'owner_scope_key']);
        (new ReflectionMethod(OwnershipSchema::class, 'partitionForeign'))->invoke($schema, $connection, 'prefix_children', ['parent_id', 'owner_scope_key'], 'prefix_parents', ['id', 'owner_scope_key']);

        expect($connection->getSchemaBuilder()->getForeignKeys('prefix_children'))->toHaveCount(1)
            ->and($connection->getSchemaBuilder()->getForeignKeys('prefix_children')[0]['foreign_table'])->toBe($prefix.'prefix_parents');
    }
});
