<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipInstallationState;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Adoption\InterruptedRecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Adoption\InterruptedRemoteRecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Adoption\QueuedProbe;
use Nvl\Ownership\Tests\Fixtures\Adoption\Record;
use Nvl\Ownership\Tests\Fixtures\Adoption\RecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Adoption\RemoteRecord;
use Nvl\Ownership\Tests\Fixtures\Adoption\RemoteRecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    if (! in_array(getenv('NVL_OWNERSHIP_TEST_DRIVER'), ['pgsql', 'mysql', 'mariadb'], true)) {
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
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('remote.records', 'remote', RemoteRecord::class, OwnershipDefinition::captured()));
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

describe('explicit constraint refresh', function (): void {
    beforeEach(function (): void {
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        $this->coordinator = app(OwnershipAdoptionCoordinator::class);
        $this->plan = $this->coordinator->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
        $this->coordinator->backfill($this->plan, 100, $this->operation);
        $this->coordinator->activate($this->plan, $this->operation);
        foreach (['sqlite', 'ownership_remote'] as $name) {
            DB::connection($name)->table('ownership_test_records')->insert([
                'id' => 2, 'company_id' => 7, 'owner_scope_key' => $this->owner->scopeKey(),
                'owner_reference' => json_encode($this->owner->toArray(), JSON_THROW_ON_ERROR),
            ]);
        }
        $this->refreshOperation = new OwnershipOperation('ownership.constraints.refresh', 'operator', '1');
    });

    it('refreshes only constraints while conserving post-adoption rows and immutable generation facts', function (): void {
        $before = [];
        foreach (['sqlite', 'ownership_remote'] as $name) {
            $before[$name] = DB::connection($name)->table('ownership_test_records')->orderBy('id')->get()->all();
        }
        $calls = [];
        $generations = app(OwnershipInstallationState::class)->generations();
        $this->allowOwners();
        Company::query()->whereKey(7)->update(['active' => false]);
        config(['ownership_test.callback' => static fn () => throw new RuntimeException('Preparation must not run during refresh.')]);
        config(['ownership_test.activate_callback' => static function (string $resource) use (&$calls): void {
            $calls[] = $resource;
        }]);
        $this->coordinator->refreshConstraints($this->coordinator->resume($this->plan->id), $this->refreshOperation);

        expect($calls)->toBe(['fixture.records', 'remote.records'])
            ->and(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('active')
            ->and(DB::table('nvl_ownership_legacy_owner_mappings')->count())->toBe(2)
            ->and(app(OwnershipInstallationState::class)->generations())->toBe($generations);
        foreach (['sqlite', 'ownership_remote'] as $name) {
            expect(DB::connection($name)->table('ownership_test_records')->orderBy('id')->get()->all())->toEqual($before[$name]);
        }
        expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), static fn () => true))->toThrow(OwnershipViolation::class);
        expect(fn () => $this->coordinator->activate($this->plan, $this->operation))->toThrow(OwnershipViolation::class)
            ->and(fn () => $this->coordinator->backfill($this->plan, 100, $this->operation))->toThrow(OwnershipViolation::class);
    });

    it('conserves current rows after a legitimately removed original adoption record', function (): void {
        foreach (['sqlite', 'ownership_remote'] as $name) {
            DB::connection($name)->table('ownership_test_records')->where('id', 1)->delete();
        }
        $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation);
        expect(DB::table('nvl_ownership_legacy_owner_mappings')->count())->toBe(2)
            ->and(DB::table('ownership_test_records')->pluck('id')->all())->toBe([2])
            ->and(DB::connection('ownership_remote')->table('ownership_test_records')->pluck('id')->all())->toBe([2]);
        expect(fn () => $this->coordinator->activate($this->plan, $this->operation))->toThrow(OwnershipConfigurationInvalid::class);
    });

    it('requires fresh authorization and unchanged configuration before fencing a refresh', function (string $failure): void {
        if ($failure === 'actor') {
            $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
            {
                public function authorize(OwnershipOperation $operation): void
                {
                    throw new OwnershipViolation('Denied refresh operator.');
                }
            });
        } elseif ($failure === 'configuration') {
            config(['nvl-core.owners' => [Company::class]]);
        }
        expect(fn () => $this->coordinator->refreshConstraints($this->plan, $failure === 'purpose' ? $this->operation : $this->refreshOperation))
            ->toThrow($failure === 'configuration' ? OwnershipConfigurationInvalid::class : OwnershipViolation::class);
        expect(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('active')
            ->and(DB::table(Tables::Markers)->value('state'))->toBe('active');
    })->with(['actor', 'configuration', 'purpose']);

    it('refuses a replacement plan before persisting any new run or mappings', function (): void {
        $runs = DB::table('nvl_ownership_adoption_runs')->count();
        $mappings = DB::table('nvl_ownership_legacy_owner_mappings')->count();
        expect(fn () => $this->coordinator->prepare(['fixture', 'remote'], $this->mappings, $this->operation))->toThrow(OwnershipSchemaNotReady::class);
        expect(DB::table('nvl_ownership_adoption_runs')->count())->toBe($runs)
            ->and(DB::table('nvl_ownership_legacy_owner_mappings')->count())->toBe($mappings);
    });

    it('refuses a resource-empty refresh before changing its accepted active run', function (): void {
        $adapter = new class implements OwnershipAdoptionAdapter
        {
            public function resources(): array
            {
                return [];
            }

            public function prepare(OwnershipAdoptionPlan $plan): void {}

            public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
            {
                return new OwnershipBackfillResult(null, 0);
            }

            public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
            {
                return new OwnershipVerification([]);
            }

            public function activate(OwnershipAdoptionPlan $plan): void {}
        };
        $this->app->instance($adapter::class, $adapter);
        app(OwnershipAdoptionRegistry::class)->register('empty-refresh', $adapter::class);
        $plan = $this->coordinator->prepare(['empty-refresh'], [], $this->operation);
        $this->coordinator->backfill($plan, 100, $this->operation);
        $this->coordinator->activate($plan, $this->operation);
        $before = DB::table('nvl_ownership_adoption_runs')->where('id', $plan->id)->first();
        expect(fn () => $this->coordinator->refreshConstraints($plan, $this->refreshOperation))->toThrow(OwnershipSchemaNotReady::class);
        expect(DB::table('nvl_ownership_adoption_runs')->where('id', $plan->id)->first())->toEqual($before);
    });

    it('conserves binary host fields without persisting private payloads in the refresh journal', function (): void {
        foreach (['sqlite', 'ownership_remote'] as $name) {
            $connection = DB::connection($name);
            $connection->getSchemaBuilder()->table('ownership_test_records', static fn (Blueprint $table) => $table->binary('private_bytes')->nullable());
            $value = $connection->getDriverName() === 'pgsql' ? DB::raw("decode('00fffef1736563726574', 'hex')") : "\x00\xFF\xFE\xF1secret";
            $connection->table('ownership_test_records')->where('id', 1)->update(['private_bytes' => $value]);
        }
        config(['ownership_test.interrupt' => 'remote.records']);
        expect(fn () => $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation))->toThrow(RuntimeException::class);
        $checkpoint = DB::table('nvl_ownership_adoption_runs')->value('checkpoints');
        expect($checkpoint)->toBeString()->not->toContain('secret');
        config(['ownership_test.interrupt' => null]);
        $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation);
        expect(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('active');
    });

    it('retains its original snapshot through interruption and rejects intervening data changes', function (): void {
        config(['ownership_test.interrupt' => 'remote.records']);
        expect(fn () => $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation))->toThrow(RuntimeException::class);
        $checkpoint = DB::table('nvl_ownership_adoption_runs')->value('checkpoints');
        expect(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('refreshing')
            ->and(fn () => app(OwnershipInstallationState::class)->generations())->toThrow(OwnershipSchemaNotReady::class)
            ->and(fn () => $this->coordinator->activate($this->plan, $this->operation))->toThrow(OwnershipSchemaNotReady::class)
            ->and(fn () => $this->coordinator->backfill($this->plan, 100, $this->operation))->toThrow(OwnershipSchemaNotReady::class);
        $original = DB::table('ownership_test_records')->where('id', 1)->value('owner_reference');
        DB::table('ownership_test_records')->where('id', 1)->update(['owner_reference' => '{"private":"changed"}']);
        config(['ownership_test.interrupt' => null]);
        expect(fn () => $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation))->toThrow(OwnershipViolation::class);
        expect(DB::table('nvl_ownership_adoption_runs')->value('checkpoints'))->toBe($checkpoint)
            ->and(DB::table(Tables::Markers)->value('state'))->toBe('prepared');
        DB::table('ownership_test_records')->where('id', 1)->update(['owner_reference' => $original]);
        $this->coordinator->refreshConstraints($this->coordinator->resume($this->plan->id), $this->refreshOperation);
        expect(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('active')
            ->and(app(OwnershipInstallationState::class)->generations())->toHaveCount(2);
    });

    it('keeps a failed refresh fenced when an activation callback writes an application row', function (): void {
        $original = DB::table('ownership_test_records')->where('id', 1)->value('owner_reference');
        config(['ownership_test.activate_callback' => static function (string $resource): void {
            if ($resource === 'fixture.records') {
                DB::table('ownership_test_records')->where('id', 1)->update(['owner_reference' => '{"private":"changed"}']);
            }
        }]);
        expect(fn () => $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation))->toThrow(OwnershipViolation::class);
        expect(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('refreshing');
        config(['ownership_test.activate_callback' => null]);
        expect(fn () => $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation))->toThrow(OwnershipViolation::class);
        DB::table('ownership_test_records')->where('id', 1)->update(['owner_reference' => $original]);
        $this->coordinator->refreshConstraints($this->plan, $this->refreshOperation);
        expect(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('active');
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

it('replays a committed backfill page and conserves populated sources through interrupted activation', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $registry = new OwnershipAdoptionRegistry;
    $registry->register('fixture', InterruptedRecordsAdapter::class);
    $registry->register('remote', InterruptedRemoteRecordsAdapter::class);
    $this->app->instance(OwnershipAdoptionRegistry::class, $registry);
    $ownerB = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(8));
    $assignments = ['fixture.records' => [$this->owner, $ownerB, $this->owner], 'remote.records' => [$ownerB, $this->owner, $ownerB]];
    $mappings = [];
    foreach (['sqlite' => 'fixture.records', 'ownership_remote' => 'remote.records'] as $connection => $resource) {
        $database = DB::connection($connection);
        $database->getSchemaBuilder()->table('ownership_test_records', static function (Blueprint $table): void {
            $table->string('payload')->nullable();
        });
        $database->table('ownership_test_records')->where('id', 1)->update(['payload' => $resource.'-1']);
        foreach ([2, 3] as $id) {
            $database->table('ownership_test_records')->insert(['id' => $id, 'payload' => $resource.'-'.$id]);
        }
        foreach ($assignments[$resource] as $offset => $owner) {
            $mappings[] = new OwnershipMapping($resource, (string) ($offset + 1), $owner);
        }
    }

    $writtenRows = [];
    Record::saved(static function (Record $record) use (&$writtenRows): void {
        $writtenRows[] = 'fixture.records:'.$record->getKey();
    });
    RemoteRecord::saved(static function (RemoteRecord $record) use (&$writtenRows): void {
        $writtenRows[] = 'remote.records:'.$record->getKey();
    });
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture', 'remote'], $mappings, $this->operation);
    config(['ownership_test.interrupt_backfill' => 'fixture.records']);
    expect(fn () => $coordinator->backfill($plan, 1, $this->operation))->toThrow(RuntimeException::class, 'Interrupted after a committed source page.');
    expect(DB::table('ownership_test_records')->whereNotNull('owner_scope_key')->count())->toBe(1)
        ->and(DB::connection('ownership_remote')->table('ownership_test_records')->whereNotNull('owner_scope_key')->count())->toBe(0)
        ->and(fn () => app(OwnershipInstallationState::class)->generations())->toThrow(OwnershipSchemaNotReady::class)
        ->and($coordinator->resume($plan->id))->toEqual($plan);
    $checkpoints = json_decode(DB::table('nvl_ownership_adoption_runs')->value('checkpoints'), true, 512, JSON_THROW_ON_ERROR);
    expect($checkpoints['adapters']['fixture']['cursor'])->toBeNull()
        ->and($writtenRows)->toBe(['fixture.records:1'])
        ->and($coordinator->backfill($plan, 1, $this->operation))->toBeFalse()
        ->and($writtenRows)->toBe(['fixture.records:1', 'fixture.records:1']);

    $finished = false;
    for ($page = 0; $page < 10 && ! $finished; $page++) {
        $finished = $coordinator->backfill($plan, 1, $this->operation);
    }
    expect($finished)->toBeTrue()->and($coordinator->verify($plan)->passed())->toBeTrue();
    config(['ownership_test.interrupt' => 'remote.records']);
    expect(fn () => $coordinator->activate($plan, $this->operation))->toThrow(RuntimeException::class)
        ->and(fn () => app(OwnershipInstallationState::class)->generations())->toThrow(OwnershipSchemaNotReady::class);
    config(['ownership_test.interrupt' => null]);
    $coordinator->activate($coordinator->resume($plan->id), $this->operation);

    foreach (['sqlite' => 'fixture.records', 'ownership_remote' => 'remote.records'] as $connection => $resource) {
        $rows = DB::connection($connection)->table('ownership_test_records')->orderBy('id')->get();
        expect($rows->pluck('id')->map(static fn ($id): int => (int) $id)->all())->toBe([1, 2, 3]);
        foreach ($rows as $offset => $row) {
            $owner = $assignments[$resource][$offset];
            expect((string) $row->company_id)->toBe($owner->key)
                ->and($row->owner_scope_key)->toBe($owner->scopeKey())
                ->and(json_decode($row->owner_reference, true, 512, JSON_THROW_ON_ERROR))->toEqual($owner->toArray())
                ->and($row->payload)->toBe($resource.'-'.($offset + 1));
        }
    }
    expect(DB::table('nvl_ownership_legacy_owner_mappings')->count())->toBe(6)
        ->and($writtenRows)->toBe(['fixture.records:1', 'fixture.records:1', 'fixture.records:2', 'fixture.records:3', 'remote.records:1', 'remote.records:2', 'remote.records:3'])
        ->and(app(OwnershipInstallationState::class)->generations())->toHaveCount(2)
        ->and(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('active');
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

it('requires fresh native owner admission after adoption and worker scope reset', function (): void {
    $this->allowOwners();
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
    $coordinator->backfill($plan, 100, $this->operation);
    $coordinator->activate($plan, $this->operation);
    $read = static function (): string {
        $context = app(OwnershipContext::class);
        $owner = $context->requireOwner();
        app(OwnershipInstallationState::class)->assertSnapshot($context->snapshot());

        return $owner->key;
    };
    expect(fn () => $read())->toThrow(OwnershipViolation::class);
    $actor = Actor::query()->firstOrFail();
    expect(app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, $read))->toBe('7');
    expect(app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, $read))->toBe('8');
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function () use ($actor, $read): void {
        app()->forgetScopedInstances();
        expect(fn () => $read())->toThrow(OwnershipViolation::class);
        expect(app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), $actor, $read))->toBe('8');
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

it('fences native package entry during adoption after an unadopted storage probe', function (): void {
    app(PersistedOwnershipStorage::class)->assertUnadopted(DB::connection(), 'fixture.records', 'ownership_test_records');
    $boundary = app(OwnershipBoundary::class);
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    config(['ownership_test.callback' => function () use ($boundary): void {
        expect(fn () => $boundary->key('fixture.records', 'escaped'))->toThrow(OwnershipViolation::class);
    }]);
    app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
});

it('rejects canonical owner drift even when an adapter reports a healthy graph', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $this->app->bind(RecordsAdapter::class, static fn () => new class extends RecordsAdapter
    {
        public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
        {
            return new OwnershipVerification([]);
        }
    });
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture', 'remote'], $this->mappings, $this->operation);
    $coordinator->backfill($plan, 100, $this->operation);
    $verified = $coordinator->verify($plan);
    expect($verified->passed())->toBeTrue()
        ->and($verified->resources['fixture.records']['mapped'])->toBe(1)
        ->and($verified->resources['fixture.records']['persisted'])->toBe(1)
        ->and($verified->resources['fixture.records']['owners'])->toBe([$this->owner->scopeKey() => 1])
        ->and($verified->resources['remote.records']['source_store'])->not->toBe($verified->resources['fixture.records']['source_store']);
    DB::table('ownership_test_records')->update(['company_id' => 8, 'owner_scope_key' => app(OwnerIdentityResolver::class)->reference(Company::findOrFail(8))->scopeKey()]);
    DB::connection('ownership_remote')->table('ownership_test_records')->update(['owner_scope_key' => $this->owner->scopeKey()]);
    expect($coordinator->verify($plan)->passed())->toBeFalse();
});

it('runs the CLI transition from a read-only reviewed plan and retains fresh host authorization', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'nvl-ownership-cli-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate test input.');
    }
    try {
        $input = ['packages' => ['fixture', 'remote'], 'mappings' => array_map(fn (OwnershipMapping $mapping): array => ['resource' => $mapping->resource, 'record_id' => $mapping->recordId, 'owner' => $mapping->owner?->toArray()], $this->mappings)];
        file_put_contents($path, json_encode($input, JSON_THROW_ON_ERROR));
        expect(Artisan::call('nvl:ownership:plan', ['--input' => $path]))->toBe(0);
        $review = json_decode(Artisan::output(), true, 32, JSON_THROW_ON_ERROR);
        expect($review['sources']['fixture.records']['rows'])->toBe(1)
            ->and(DB::table('nvl_ownership_adoption_runs')->count())->toBe(0)
            ->and(DB::table('nvl_ownership_resource_markers')->count())->toBe(0);
        ksort($review['sources']['fixture.records']);
        file_put_contents($path, json_encode($review, JSON_THROW_ON_ERROR));
        $arguments = ['--plan' => $review['id'], '--input' => $path, '--actor-type' => 'operator', '--actor-id' => 'reviewed-cli'];
        expect(fn () => Artisan::call('nvl:ownership:prepare', $arguments))->toThrow(OwnershipViolation::class);
        expect(DB::table('nvl_ownership_adoption_runs')->count())->toBe(0);
        $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
        {
            public function authorize(OwnershipOperation $operation): void {}
        });
        expect(Artisan::call('nvl:ownership:prepare', $arguments))->toBe(0);
        $phase = ['--plan' => $review['id'], '--actor-type' => 'operator', '--actor-id' => 'reviewed-cli'];
        expect(fn () => Artisan::call('nvl:ownership:prepare', $arguments))->toThrow(OwnershipConfigurationInvalid::class);
        expect(Artisan::call('nvl:ownership:backfill', $phase + ['--limit' => '10']))->toBe(0);
        $checkpointCount = DB::table('nvl_ownership_adoption_checkpoints')->count();
        expect(Artisan::call('nvl:ownership:verify', ['--plan' => $review['id']]))->toBe(0)
            ->and(DB::table('nvl_ownership_adoption_checkpoints')->count())->toBe($checkpointCount);
        expect(Artisan::call('nvl:ownership:activate', $phase))->toBe(0)
            ->and(DB::table('nvl_ownership_adoption_runs')->where('id', $review['id'])->value('status'))->toBe('active')
            ->and(DB::table('ownership_test_records')->where('id', 1)->value('company_id'))->toBeIn([7, '7']);
    } finally {
        unlink($path);
    }
});

it('rejects empty unscoped resources before planning or preparing any adoption state', function (string $phase): void {
    $resources = new OwnershipResourceRegistry;
    $resources->register(new OwnershipResourceDefinition('fixture.records', 'fixture', Record::class, OwnershipDefinition::column(Company::class, 'company_id')));
    $resources->register(new OwnershipResourceDefinition('remote.records', 'remote', RemoteRecord::class, OwnershipDefinition::unscoped()));
    $this->app->instance(OwnershipResourceRegistry::class, $resources);
    DB::connection('ownership_remote')->table('ownership_test_records')->delete();
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $path = tempnam(sys_get_temp_dir(), 'unscoped-adoption-');
    if ($path === false) {
        throw new RuntimeException('Unable to allocate test input.');
    }
    try {
        file_put_contents($path, json_encode(['packages' => ['fixture', 'remote'], 'mappings' => []], JSON_THROW_ON_ERROR));
        $operation = $phase === 'plan'
            ? fn () => Artisan::call('nvl:ownership:plan', ['--input' => $path])
            : fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['fixture', 'remote'], [], $this->operation);
        expect($operation)->toThrow(OwnershipConfigurationInvalid::class, 'Unscoped resources cannot be adopted');
        expect(DB::table('nvl_ownership_adoption_runs')->count())->toBe(0)
            ->and(DB::table('nvl_ownership_resource_markers')->count())->toBe(0)
            ->and(DB::connection('ownership_remote')->table('nvl_ownership_resource_markers')->count())->toBe(0)
            ->and(DB::connection('ownership_remote')->getSchemaBuilder()->hasColumn('ownership_test_records', 'owner_scope_key'))->toBeFalse();
    } finally {
        unlink($path);
    }
})->with(['plan', 'prepare']);

it('validates populated mappings once before and once after each adapter callback', function (): void {
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $mappings = [];
    foreach (['sqlite' => 'fixture.records', 'ownership_remote' => 'remote.records'] as $connection => $resource) {
        DB::connection($connection)->table('ownership_test_records')->insert(array_map(static fn (int $id): array => ['id' => $id], range(2, 150)));
        foreach (range(1, 150) as $id) {
            $mappings[] = new OwnershipMapping($resource, (string) $id, $this->owner);
        }
    }
    $queries = 0;
    $validations = 0;
    DB::listen(static function (QueryExecuted $query) use (&$queries, &$validations): void {
        $queries++;
        if (preg_match('/^select .id. from .ownership_test_records. where .id. = \?/i', $query->sql) === 1) {
            $validations++;
        }
    });
    $measure = static function (Closure $phase) use (&$queries, &$validations): array {
        [$queries, $validations] = [0, 0];
        $phase();

        return ['validations' => $validations, 'queries' => $queries];
    };
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = null;
    $metrics = [
        'prepare' => $measure(function () use ($coordinator, $mappings, &$plan): void {
            $plan = $coordinator->prepare(['fixture', 'remote'], $mappings, $this->operation);
        }),
        'backfill' => $measure(fn () => expect($coordinator->backfill($plan, 1000, $this->operation))->toBeTrue()),
        'verify' => $measure(fn () => expect($coordinator->verify($plan)->passed())->toBeTrue()),
        'activate' => $measure(fn () => $coordinator->activate($plan, $this->operation)),
    ];
    $path = getenv('NVL_ADOPTION_METRICS_PATH');
    if (is_string($path) && $path !== '') {
        file_put_contents($path, json_encode(['mappings' => 300, 'adapters' => 2, ...$metrics], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    // 300 mappings, two adapters: one pass at phase entry (or ingestion) and one after every adapter callback.
    expect(array_column($metrics, 'validations', null))->toBe([300 * 3, 300 * 5, 300 * 3, 300 * 8])
        ->and(DB::table('nvl_ownership_adoption_runs')->value('status'))->toBe('active')
        ->and(DB::table('ownership_test_records')->whereNull('owner_scope_key')->count())->toBe(0);
});
