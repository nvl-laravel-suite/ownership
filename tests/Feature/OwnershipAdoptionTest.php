<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipInstallationState;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Adoption\QueuedProbe;
use Nvl\Ownership\Tests\Fixtures\Adoption\Record;
use Nvl\Ownership\Tests\Fixtures\Adoption\RecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Adoption\RemoteRecord;
use Nvl\Ownership\Tests\Fixtures\Adoption\RemoteRecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Tenancy\Contracts\TenantBoundary;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantId;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;

beforeEach(function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
        config(['database.connections.ownership_remote' => config('database.connections.sqlite')]);
    }
    foreach (['sqlite', 'ownership_remote'] as $connection) {
        config(['nvl-ownership.connection' => $connection]);
        (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
        DB::connection($connection)->getSchemaBuilder()->create('ownership_test_records', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('company_id')->nullable();
        });
        DB::connection($connection)->table('ownership_test_records')->insert(['id' => 1]);
    }
    config(['nvl-ownership.connection' => 'sqlite']);
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.records', 'fixture', Record::class, OwnershipDefinition::column(Company::class, 'company_id')));
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('remote.records', 'remote', RemoteRecord::class, OwnershipDefinition::column(Company::class, 'company_id')));
    app(OwnershipAdoptionRegistry::class)->register('fixture', RecordsAdapter::class);
    app(OwnershipAdoptionRegistry::class)->register('remote', RemoteRecordsAdapter::class);
    $this->operation = new OwnershipOperation('adopt-reviewed-records', 'operator', '1');
    $this->owner = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
    $this->mappings = [new OwnershipMapping('fixture.records', '1', $this->owner), new OwnershipMapping('remote.records', '1', $this->owner)];
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
});

it('denies maintenance adoption without fresh host authorization', function (): void {
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], $this->mappings, $this->operation))
        ->toThrow(OwnershipViolation::class);
    expect(DB::table('nvl_ownership_adoption_runs')->count())->toBe(0);
});

it('rejects an explicit central mapping for a resource that requires a native owner', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], [new OwnershipMapping('fixture.records', '1', null), $this->mappings[1]], $this->operation))
        ->toThrow(OwnershipConfigurationInvalid::class);
});

it('keeps interrupted multi-source activation closed and resumes the identical plan', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
    expect(fn () => (new PersistedOwnershipStorage)->assertUnadopted(DB::connection('ownership_remote'), 'changed.resource', 'ownership_test_records'))->toThrow(OwnershipSchemaNotReady::class);
    expect(fn () => app(OwnershipInstallationState::class)->generations())->toThrow(OwnershipSchemaNotReady::class);
    expect($coordinator->backfill($plan, 100, $this->operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    config(['ownership_test.interrupt' => 'remote.records']);
    expect(fn () => $coordinator->activate($plan, $this->operation))->toThrow(RuntimeException::class)
        ->and(fn () => app(OwnershipInstallationState::class)->generations())->toThrow(OwnershipSchemaNotReady::class);
    config(['ownership_test.interrupt' => null]);
    expect($coordinator->resume($plan->id))->toEqual($plan);
    $coordinator->activate($plan, $this->operation);
    expect(array_keys(app(OwnershipInstallationState::class)->generations()))->toBe(['fixture.records', 'remote.records'])
        ->and(DB::table('nvl_ownership_operation_facts')->count())->toBe(4)
        ->and(DB::table('nvl_ownership_adoption_checkpoints')->count())->toBeGreaterThan(4);
    $queries = [];
    DB::listen(static function (QueryExecuted $query) use (&$queries): void {
        $queries[] = $query->sql;
    });
    app(OwnershipInstallationState::class)->generations();
    expect(count($queries))->toBeLessThan(11);
    DB::connection('ownership_remote')->table('nvl_ownership_resource_markers')->update(['generation' => (string) Str::uuid()]);
    expect(fn () => app(OwnershipInstallationState::class)->generations())->toThrow(OwnershipSchemaNotReady::class);
});

it('rejects duplicate assignments before package writes and persists the authorized attempt', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], [...$this->mappings, $this->mappings[0]], $this->operation))
        ->toThrow(OwnershipConfigurationInvalid::class);
    expect(DB::table('nvl_ownership_operation_facts')->count())->toBe(1)
        ->and(DB::table('nvl_ownership_adoption_runs')->count())->toBe(0)
        ->and(Record::query()->firstOrFail()->getAttribute('company_id'))->toBeNull();
});

it('rejects changed immutable mapping and owner identity when resuming', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
    DB::table('nvl_ownership_legacy_owner_mappings')->where('resource', 'fixture.records')->update(['record_id' => '99']);
    expect(fn () => $coordinator->resume($plan->id))->toThrow(OwnershipConfigurationInvalid::class);
});

it('fences ordinary owner entry and queue publication inside every adapter callback', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    config(['ownership_test.callback' => function (): void {
        expect(fn () => app(OwnershipRunner::class)->run(Company::query()->firstOrFail(), Actor::query()->firstOrFail(), fn () => true))->toThrow(OwnershipViolation::class);
        Queue::push(new QueuedProbe);
    }]);
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], $this->mappings, $this->operation))
        ->toThrow(OwnershipViolation::class);
    expect(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('prepared');
});

it('admits a legacy mapping only within the matching freshly admitted owner generation', function (): void {
    $this->allowOwners();
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $tenant = new TenantId('01954c28-56ec-7bda-9721-e5c05d7e1a54');
    $mappings = array_map(fn (OwnershipMapping $mapping) => new OwnershipMapping($mapping->resource, $mapping->recordId, $mapping->owner, legacyTenantId: $tenant->value), $this->mappings);
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture', 'remote'], $mappings, $this->operation);
    $coordinator->backfill($plan, 100, $this->operation);
    $coordinator->activate($plan, $this->operation);
    $legacy = app(TenantRunner::class);
    expect(fn () => $legacy->run($tenant, fn () => true))->toThrow(OwnershipViolation::class);
    $actor = Actor::query()->firstOrFail();
    expect(app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, fn () => $legacy->run($tenant, fn () => 'accepted')))->toBe('accepted');
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, fn () => $legacy->run($tenant, fn () => true)))->toThrow(OwnershipViolation::class);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($legacy, $tenant, $actor): void {
        app()->forgetScopedInstances();
        expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, fn () => $legacy->run($tenant, fn () => true)))->toThrow(OwnershipViolation::class);
    });
});

it('rejects stale installation generations when a request has already entered', function (): void {
    $this->allowOwners();
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
    $coordinator->backfill($plan, 100, $this->operation);
    $coordinator->activate($plan, $this->operation);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $snapshot = app(OwnershipContext::class)->snapshot();
        expect($snapshot->generations)->toBe(app(OwnershipInstallationState::class)->generations());
        DB::connection('ownership_remote')->table('nvl_ownership_stores')->update(['store_id' => (string) Illuminate\Support\Str::uuid()]);
        expect(fn () => app(OwnershipInstallationState::class)->assertSnapshot($snapshot))->toThrow(OwnershipSchemaNotReady::class);
    });
});

it('rejects reviewed mappings that name a missing physical source record', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], [...$this->mappings, new OwnershipMapping('fixture.records', '999', $this->owner)], $this->operation))
        ->toThrow(OwnershipConfigurationInvalid::class);
});

it('fences legacy package entry during adoption even after a negative storage probe', function (): void {
    app(TenantResourceRegistry::class)->register(new TenantResourceDefinition('fixture.records', 'fixture', Record::class));
    $legacy = app(TenantBoundary::class);
    expect($legacy->key('fixture.records', 'baseline'))->toBe('baseline');
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    config(['ownership_test.callback' => function () use ($legacy): void {
        expect(fn () => $legacy->key('fixture.records', 'escaped'))->toThrow(OwnershipViolation::class);
    }]);
    app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
});
