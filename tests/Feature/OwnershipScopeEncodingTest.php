<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipAdoptionScope;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Tests\Fixtures\Resources\AssetsAdapter;
use Nvl\Ownership\Tests\Fixtures\Resources\ScopeEncodingAsset;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    DB::connection()->getSchemaBuilder()->create('ownership_test_scope_encoding', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('scope', 100)->default('default');
        $table->string('subject_type', 255)->default('host');
        $table->string('subject_id', 255)->default('subject');
    });
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.encoding', 'assets', ScopeEncodingAsset::class, ScopeEncodingAsset::ownershipDefinition(), allowsCentral: true));
});

it('keeps full polymorphic ownership uniqueness within native MySQL index limits', function (): void {
    $connection = DB::connection();
    $scope = app(OwnershipAdoptionScope::class);
    $scope->during('scope-encoding', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding'));
    $connection->getSchemaBuilder()->table('ownership_test_scope_encoding', static fn (Blueprint $table) => $table->unique(['owner_scope_key', 'scope', 'subject_type', 'subject_id'], 'scope_encoding_full_identity_unique'));
    $scope->during('scope-encoding', AssetsAdapter::class, 'activate', static fn () => app(OwnershipSchema::class)->constrain('assets.encoding'));
    $column = array_find($connection->getSchemaBuilder()->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');

    expect(fn () => $connection->table('ownership_test_scope_encoding')->insert(['id' => 1, 'owner_scope_key' => null]))->toThrow(QueryException::class);
    if ($connection->getDriverName() !== 'sqlite') {
        expect($column['nullable'])->toBeFalse();
    }
    if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        expect($column['collation'])->toBe('ascii_bin')->and($column['type'])->toBe('varchar(191)');
    }
    $index = array_find($connection->getSchemaBuilder()->getIndexes('ownership_test_scope_encoding'), static fn (array $index): bool => $index['name'] === 'scope_encoding_full_identity_unique');
    expect($index['columns'])->toBe(['owner_scope_key', 'scope', 'subject_type', 'subject_id']);
});

it('compiles ASCII partition declarations with both supported MySQL family grammars', function (string $driver): void {
    $connection = $driver === 'mysql'
        ? new MySqlConnection(static fn () => throw new LogicException('DDL compilation must not open a PDO.'), 'scope_plan', '', ['driver' => $driver, 'version' => '8.4.0'])
        : new MariaDbConnection(static fn () => throw new LogicException('DDL compilation must not open a PDO.'), 'scope_plan', '', ['driver' => $driver, 'version' => '11.4.0']);
    config(['database.connections.scope_plan' => ['driver' => $driver, 'database' => 'scope_plan']]);
    DB::extend('scope_plan', static fn () => $connection);
    $original = DB::getDefaultConnection();
    try {
        DB::setDefaultConnection('scope_plan');
        $queries = $connection->pretend(static fn () => app(OwnershipAdoptionScope::class)->during('scope-plan', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding')));
        $declaration = array_find($queries, static fn (array $query): bool => str_contains($query['query'], 'add `owner_scope_key`'));
        expect($declaration['query'])->toContain('varchar(191)', 'character set ascii', "collate 'ascii_bin'");
    } finally {
        DB::setDefaultConnection($original);
        DB::purge('scope_plan');
    }
})->with(['mysql', 'mariadb']);

it('preserves the declared scope default while activating required native columns', function (): void {
    $connection = DB::connection();
    $connection->getSchemaBuilder()->table('ownership_test_scope_encoding', static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->nullable()->default('central'));
    $scope = app(OwnershipAdoptionScope::class);
    $scope->during('scope-default', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding'));
    $scope->during('scope-default', AssetsAdapter::class, 'activate', static fn () => app(OwnershipSchema::class)->constrain('assets.encoding'));
    $connection->table('ownership_test_scope_encoding')->insert(['id' => 1]);

    expect($connection->table('ownership_test_scope_encoding')->value('owner_scope_key'))->toBe('central');
});

it('converts canonical legacy scope values without losing their bytes or defaults', function (bool $nullable): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL family character-set conversion.');
    }
    $connection->getSchemaBuilder()->table('ownership_test_scope_encoding', static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->charset('utf8mb4')->collation('utf8mb4_bin')->nullable($nullable)->default('central')->comment('Host partition'));
    $key = 'owner:'.str_repeat('a', 64);
    $connection->table('ownership_test_scope_encoding')->insert(['id' => 1, 'owner_scope_key' => $key]);
    app(OwnershipAdoptionScope::class)->during('scope-convert', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding'));
    $column = array_find($connection->getSchemaBuilder()->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
    $connection->table('ownership_test_scope_encoding')->insert(['id' => 2]);

    expect($column['collation'])->toBe('ascii_bin')->and($column['nullable'])->toBe($nullable)
        ->and($column['comment'])->toBe('Host partition')
        ->and($connection->table('ownership_test_scope_encoding')->where('id', 1)->value('owner_scope_key'))->toBe($key)
        ->and($connection->table('ownership_test_scope_encoding')->where('id', 2)->value('owner_scope_key'))->toBe('central');
})->with([true, false]);

it('requires a reviewed migration before changing legacy scope foreign keys', function (string $direction): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL family character-set conversion.');
    }
    $schema = $connection->getSchemaBuilder();
    $schema->table('ownership_test_scope_encoding', static function (Blueprint $table): void {
        $table->string('owner_scope_key', 191)->charset('utf8mb4')->collation('utf8mb4_bin')->nullable();
        $table->unique(['id', 'owner_scope_key'], 'scope_encoding_parent_identity');
    });
    $schema->create('ownership_test_scope_dependency', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('owner_scope_key', 191)->charset('utf8mb4')->collation('utf8mb4_bin')->nullable();
        $table->unique(['id', 'owner_scope_key'], 'scope_dependency_parent_identity');
    });
    $child = $direction === 'incoming' ? 'ownership_test_scope_dependency' : 'ownership_test_scope_encoding';
    $parent = $direction === 'incoming' ? 'ownership_test_scope_encoding' : 'ownership_test_scope_dependency';
    $schema->table($child, static fn (Blueprint $table) => $table->foreign(['id', 'owner_scope_key'], 'scope_encoding_dependency_foreign')->references(['id', 'owner_scope_key'])->on($parent));

    expect(fn () => app(OwnershipAdoptionScope::class)->during('scope-dependency', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding')))
        ->toThrow(OwnershipConfigurationInvalid::class, 'reviewed migration');
    $column = array_find($schema->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
    expect($column['collation'])->toBe('utf8mb4_bin')->and($schema->getForeignKeys($child))->toHaveCount(1);
})->with(['incoming', 'outgoing']);

it('rejects noncanonical legacy scope values before converting their encoding', function (string $key): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('MySQL family character-set conversion.');
    }
    $connection->getSchemaBuilder()->table('ownership_test_scope_encoding', static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->charset('utf8mb4')->collation('utf8mb4_bin')->nullable());
    $connection->table('ownership_test_scope_encoding')->insert(['id' => 1, 'owner_scope_key' => $key]);

    expect(fn () => app(OwnershipAdoptionScope::class)->during('scope-invalid', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding')))
        ->toThrow(OwnershipConfigurationInvalid::class, 'canonical');
    expect($connection->table('ownership_test_scope_encoding')->value('owner_scope_key'))->toBe($key);
})->with(['legacy:ιδιοκτήτης', 'owner:'.str_repeat('A', 64), 'central ']);

it('still verifies an existing constrained UTF8 graph without changing its encoding', function (): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Existing MySQL family character-set compatibility.');
    }
    $scope = app(OwnershipAdoptionScope::class);
    $schema = app(OwnershipSchema::class);
    $scope->during('scope-legacy', AssetsAdapter::class, 'prepare', static fn () => $schema->prepare('assets.encoding'));
    $scope->during('scope-legacy', AssetsAdapter::class, 'activate', static fn () => $schema->constrain('assets.encoding'));
    $connection->getSchemaBuilder()->table('ownership_test_scope_encoding', static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->charset('utf8mb4')->collation('utf8mb4_bin')->nullable(false)->change());

    $schema->assertReady('assets.encoding');
    $column = array_find($connection->getSchemaBuilder()->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
    expect($column['collation'])->toBe('utf8mb4_bin');
});

it('preserves generated ASCII scope columns until a reviewed migration replaces them', function (string $phase): void {
    $connection = DB::connection();
    if (! in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Native MySQL family generated-column conversion.');
    }
    $schema = $connection->getSchemaBuilder();
    $schema->table('ownership_test_scope_encoding', static function (Blueprint $table): void {
        $table->string('owner_scope_key', 191)->charset('ascii')->collation('ascii_bin')->nullable()->storedAs("'central'");
        $table->json('owner_reference')->nullable();
        $table->unique(['id', 'owner_scope_key'], 'scope_generated_identity_unique');
    });
    $before = array_find($schema->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
    expect($before['collation'])->toBe('ascii_bin')->and($before['generation']['type'])->toBe('stored')->and($before['nullable'])->toBeTrue();

    expect(fn () => app(OwnershipAdoptionScope::class)->during('scope-generated', AssetsAdapter::class, $phase, static fn () => $phase === 'prepare'
        ? app(OwnershipSchema::class)->prepare('assets.encoding')
        : app(OwnershipSchema::class)->constrain('assets.encoding')))
        ->toThrow(OwnershipConfigurationInvalid::class, 'reviewed migration');
    $after = array_find($schema->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
    expect($after['generation'])->toBe($before['generation'])->and($after['nullable'])->toBe($before['nullable']);
})->with(['prepare', 'activate']);
