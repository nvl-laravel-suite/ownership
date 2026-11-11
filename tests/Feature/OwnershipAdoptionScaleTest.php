<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipCanonicalModels;
use Nvl\Ownership\Services\OwnershipConfigurationFingerprint;
use Nvl\Ownership\Services\OwnershipInstallationState;
use Nvl\Ownership\Services\OwnershipMigrationInput;
use Nvl\Ownership\Tests\Fixtures\Adoption\LargeGraphAdapter;
use Nvl\Ownership\Tests\Fixtures\Adoption\LargeGraphFixture;
use Nvl\Ownership\Tests\Fixtures\Adoption\LargeGraphOwner;
use Nvl\Ownership\Tests\Fixtures\Adoption\LargeGraphRecord;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    DB::table(Tables::Stores)->where('id', 1)->update(['store_id' => '550e8400-e29b-41d4-a716-446655440000']);
    LargeGraphFixture::register();
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
    $this->operation = new OwnershipOperation('adopt-empty-graph', 'operator', '1');
    LargeGraphOwner::$constructions = 0;
    LargeGraphRecord::$constructions = 0;
    LargeGraphAdapter::$prepared = 0;
});

it('bounds preparation metadata work for 154 empty resources and nine adapters', function (): void {
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $queries = 0;
    $stores = 0;
    $sqlMilliseconds = 0.0;
    DB::listen(static function (QueryExecuted $query) use (&$queries, &$stores, &$sqlMilliseconds): void {
        $queries++;
        $sqlMilliseconds += (float) $query->time;
        if (str_contains($query->sql, Tables::Stores) && str_starts_with(strtolower($query->sql), 'select')) {
            $stores++;
        }
    });
    $started = hrtime(true);
    $plan = $coordinator->prepare(LargeGraphFixture::packages(), [], $this->operation);
    $metrics = [
        'phase' => 'prepare', 'resources' => LargeGraphFixture::ResourceCount, 'adapters' => LargeGraphFixture::AdapterCount,
        'elapsed_ms' => (hrtime(true) - $started) / 1_000_000, 'queries' => $queries,
        'store_identity_reads' => $stores, 'sql_ms' => $sqlMilliseconds,
        'owner_constructions' => LargeGraphOwner::$constructions, 'resource_constructions' => LargeGraphRecord::$constructions,
        'configuration_hash' => $plan->configurationHash,
    ];
    $path = getenv('NVL_ADOPTION_METRICS_PATH');
    if (is_string($path) && $path !== '') {
        file_put_contents($path, json_encode($metrics, JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    expect(LargeGraphAdapter::$prepared)->toBe(9)
        ->and($plan->configurationHash)->toBe('9709ede891cd616c3f70fb99fa74a75f9b1ac11f43d5b9aafbc4e0b99fe2e0d4')
        ->and(LargeGraphOwner::$constructions)->toBeLessThanOrEqual(128)
        ->and($stores)->toBeLessThanOrEqual(64)
        ->and(DB::table(Tables::Markers)->where('generation', $plan->id)->count())->toBe(154)
        ->and(DB::table(Tables::Mappings)->where('run_id', $plan->id)->count())->toBe(0);
});

it('bounds physical store reads while reviewing the empty native graph', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'ownership-scale-review-');
    file_put_contents($path, json_encode(['packages' => LargeGraphFixture::packages(), 'mappings' => []], JSON_THROW_ON_ERROR));
    $stores = 0;
    DB::listen(static function (QueryExecuted $query) use (&$stores): void {
        if (str_contains($query->sql, Tables::Stores) && str_starts_with(strtolower($query->sql), 'select')) {
            $stores++;
        }
    });
    try {
        $review = app(OwnershipMigrationInput::class)->plan($path);
        expect($review->sources)->toHaveCount(154)
            ->and(array_unique(array_column($review->sources, 'rows')))->toBe([0])
            ->and($stores)->toBeLessThanOrEqual(8);
    } finally {
        unlink($path);
    }
});

it('completes every empty adoption phase and bounds metadata reconstruction on active reads', function (): void {
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(LargeGraphFixture::packages(), [], $this->operation);
    expect($coordinator->backfill($plan, 100, $this->operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $this->operation);
    LargeGraphOwner::$constructions = 0;
    LargeGraphRecord::$constructions = 0;

    expect(app(OwnershipInstallationState::class)->generations())->toHaveCount(154)
        ->and(LargeGraphOwner::$constructions)->toBeLessThanOrEqual(16)
        ->and(LargeGraphRecord::$constructions)->toBeLessThanOrEqual(154)
        ->and(DB::table(Tables::Runs)->where('id', $plan->id)->value('status'))->toBe('active');

    LargeGraphRecord::$constructions = 0;
    expect(app(OwnershipInstallationState::class)->generations())->toHaveCount(154)
        ->and(LargeGraphRecord::$constructions)->toBe(0);
});

it('resolves chained owner replacements canonically before sharing their metadata', function (): void {
    $previous = Relation::morphMap();
    try {
        [$middle, $final] = LargeGraphFixture::ownerReplacements();
        $owners = app(OwnerRegistry::class);
        $owners->registerReplacement('scale-owner', LargeGraphOwner::class, $middle);
        $owners->registerReplacement('scale-replacement', $middle, $final);
        expect($owners->all())->toContain($middle)
            ->and($owners->model($middle))->toBe($final);
        $source = app(OwnershipResourceRegistry::class)->get('scale-1.record_1');
        $resources = new OwnershipResourceRegistry;
        $resources->register(new OwnershipResourceDefinition('replacement.record', 'replacement', $source->model, OwnershipDefinition::column($middle, 'company_id')));
        $this->app->instance(OwnershipResourceRegistry::class, $resources);
        $fingerprints = app(OwnershipConfigurationFingerprint::class);
        $before = $fingerprints->hash(['replacement.record']);
        config(['ownership_test.final_owner_table' => 'changed_final_owner_table']);

        expect($fingerprints->hash(['replacement.record']))->not->toBe($before);
    } finally {
        Relation::morphMap($previous, false);
    }
});

it('rehashes a retained canonical resource model after its configured storage changes', function (): void {
    $model = LargeGraphFixture::configuredRecord();
    $resources = new OwnershipResourceRegistry;
    $resource = new OwnershipResourceDefinition('configured.record', 'configured', $model, OwnershipDefinition::column(LargeGraphOwner::class, 'company_id'));
    $resources->register($resource);
    $this->app->instance(OwnershipResourceRegistry::class, $resources);
    $fingerprints = app(OwnershipConfigurationFingerprint::class);
    $before = $fingerprints->hash(['configured.record']);
    $retained = app(OwnershipCanonicalModels::class)->for($resource);
    LargeGraphRecord::$constructions = 0;
    config(['ownership_test.configured_record_table' => 'ownership_scale_2']);

    expect($fingerprints->hash(['configured.record']))->not->toBe($before)
        ->and(LargeGraphRecord::$constructions)->toBe(0)
        ->and(app(OwnershipCanonicalModels::class)->for($resource))->toBe($retained);

    $replaced = new OwnershipResourceDefinition('configured.record', 'configured', LargeGraphRecord::class, $resource->ownership);
    expect(app(OwnershipCanonicalModels::class)->for($replaced))->not->toBe($retained)
        ->and(app(OwnershipCanonicalModels::class)->for($replaced)::class)->toBe(LargeGraphRecord::class);
});

it('rebuilds configuration and marker facts after an adapter callback', function (string $drift, string $exception): void {
    $previous = Relation::morphMap();
    config(['ownership_test.large_graph_callback' => static function () use ($drift): void {
        if ($drift === 'store') {
            DB::table(Tables::Stores)->where('id', 1)->update(['store_id' => '550e8400-e29b-41d4-a716-446655440001']);
        } elseif ($drift === 'owner') {
            config(['nvl-core.owners' => []]);
        } elseif ($drift === 'morph') {
            Relation::morphMap(['changed-scale-owner' => LargeGraphOwner::class] + Relation::morphMap(), false);
        } else {
            DB::table(Tables::Markers)->where('resource', 'scale-1.record_1')->update(['generation' => '550e8400-e29b-41d4-a716-446655440099']);
        }
    }]);
    try {
        expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(LargeGraphFixture::packages(), [], $this->operation))->toThrow($exception)
            ->and(LargeGraphAdapter::$prepared)->toBe(1);
        $checkpoints = json_decode(DB::table(Tables::Runs)->value('checkpoints'), true, flags: JSON_THROW_ON_ERROR);
        expect($checkpoints['adapters']['scale-1']['prepared'])->toBeFalse();
    } finally {
        Relation::morphMap($previous, false);
    }
})->with([
    'physical store identity' => ['store', OwnershipConfigurationInvalid::class],
    'owner registry' => ['owner', InvalidArgumentException::class],
    'native morph identity' => ['morph', OwnershipConfigurationInvalid::class],
    'source marker' => ['marker', OwnershipSchemaNotReady::class],
]);
