<?php

declare(strict_types=1);

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
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Symfony\Component\Process\Process;

it('leaves unowned storage usable with only the optional metadata schema installed', function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    expect(fn () => (new PersistedOwnershipStorage)->assertUnadopted(DB::connection(), 'unowned.records', 'unowned_records'))->not->toThrow(Throwable::class);
});

it('propagates actual source database failures instead of treating them as unadopted', function (): void {
    config(['database.connections.invalid_source' => [...config('database.connections.sqlite'), 'database' => '/nonexistent/ownership-test.sqlite']]);
    expect(fn () => (new PersistedOwnershipStorage)->assertUnadopted(DB::connection('invalid_source')))->toThrow(QueryException::class);
});

it('keeps real prepared source markers effective in a cold process with both runtimes absent', function (): void {
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
        $owner = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
        app(OwnershipAdoptionCoordinator::class)->prepare(['fixture'], [new OwnershipMapping('fixture.records', '1', $owner)], new OwnershipOperation('prepare-retained', 'operator', '1'));
        $probe = new Process([PHP_BINARY, __DIR__.'/../Fixtures/Adoption/runtime-removed-probe.php', json_encode($configuration, JSON_THROW_ON_ERROR)], timeout: 10);
        $probe->mustRun();
        expect($probe->getOutput())->toBe('blocked');
    } finally {
        DB::purge('ownership_persisted');
        DB::setDefaultConnection('sqlite');
        @unlink($path);
        @unlink($path.'.nvl-adoption.lock');
    }
});
