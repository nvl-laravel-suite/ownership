<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Tests\Fixtures\Adoption\Record;
use Nvl\Ownership\Tests\Fixtures\Adoption\RecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Symfony\Component\Process\Process;

it('leaves unowned storage usable with only the optional metadata schema installed', function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    expect(fn () => (new PersistedOwnershipStorage)->assertUnadopted(DB::connection(), 'unowned.records', 'unowned_records'))->not->toThrow(Throwable::class);
});

it('finds persisted markers through a PostgreSQL search path whose first schema does not exist', function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('The effective search path is a PostgreSQL concept.');
    }
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    DB::table('nvl_ownership_resource_markers')->insert([
        'resource' => 'fixture.records', 'physical_table' => 'ownership_test_records', 'state' => 'prepared', 'schema_version' => 1,
        'configuration_hash' => str_repeat('a', 64), 'generation' => '550e8400-e29b-41d4-a716-446655440000',
        'coordinator_store' => '550e8400-e29b-41d4-a716-446655440001', 'source_store' => '550e8400-e29b-41d4-a716-446655440001',
        'created_at' => now(), 'updated_at' => now(),
    ]);
    config(['database.connections.user_search_path' => [...DB::connection()->getConfig(), 'search_path' => '"$user",public', 'name' => 'user_search_path']]);
    $connection = DB::connection('user_search_path');
    expect($connection->scalar('select current_schema()'))->toBe('public')
        ->and($connection->getSchemaBuilder()->getCurrentSchemaName())->not->toBe('public');

    $storage = new PersistedOwnershipStorage;
    expect($storage->probe($connection)['markers'])->toHaveCount(1)
        ->and(fn () => $storage->assertUnadopted($connection, 'fixture.records', 'unrelated_table'))->toThrow(OwnershipSchemaNotReady::class)
        ->and(fn () => $storage->assertUnadoptedTables($connection, ['ownership_test_records']))->toThrow(OwnershipSchemaNotReady::class)
        ->and(fn () => $storage->assertUnadopted($connection, 'other.records', 'other_records'))->not->toThrow(Throwable::class);
});

it('propagates actual source database failures instead of treating them as unadopted', function (): void {
    config(['database.connections.invalid_source' => [...config('database.connections.sqlite'), 'database' => '/nonexistent/ownership-test.sqlite']]);
    expect(fn () => (new PersistedOwnershipStorage)->assertUnadopted(DB::connection('invalid_source')))->toThrow(QueryException::class);
});

it('keeps real prepared source markers effective in a cold process with both runtimes absent', function (bool $renamedProtocol): void {
    $path = tempnam(sys_get_temp_dir(), 'nvl-ownership-retained-');
    $configuration = ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true];
    config(['database.connections.ownership_persisted' => $configuration, 'nvl-ownership.connection' => 'ownership_persisted']);
    DB::setDefaultConnection('ownership_persisted');
    $schema = DB::getSchemaBuilder();
    $schema->create('ownership_test_companies', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->boolean('active');
    });
    $schema->create('ownership_test_records', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id')->nullable();
    });
    DB::table('ownership_test_companies')->insert(['id' => 7, 'active' => true]);
    DB::table('ownership_test_records')->insert(['id' => 1]);
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.records', 'fixture', Record::class, OwnershipDefinition::column(Company::class, 'company_id')));
    app(OwnershipAdoptionRegistry::class)->register('fixture', RecordsAdapter::class);
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return [];
        }
    });
    try {
        config(['ownership_test.callback' => static function (): void {
            DB::getSchemaBuilder()->table('ownership_test_records', static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->nullable());
        }]);
        $owner = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
        app(OwnershipAdoptionCoordinator::class)->prepare(['fixture'], [new OwnershipMapping('fixture.records', '1', $owner)], new OwnershipOperation('prepare-retained', 'operator', '1'));
        if ($renamedProtocol) {
            $schema->rename('nvl_ownership_resource_markers', 'previous_nvl_ownership_resource_markers');
        }
        $probe = new Process([PHP_BINARY, __DIR__.'/../Fixtures/Adoption/runtime-removed-probe.php', json_encode($configuration, JSON_THROW_ON_ERROR)], timeout: 10);
        $probe->mustRun();
        expect($probe->getOutput())->toBe('blocked');
    } finally {
        DB::purge('ownership_persisted');
        DB::setDefaultConnection('sqlite');
        @unlink($path);
        @unlink($path.'.nvl-adoption.lock');
    }
})->with(['original protocol name' => false, 'previous prefix remains discoverable' => true]);
