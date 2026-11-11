<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipInstallationState;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ChildAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\FreshAssetsAdapter;
use Nvl\Ownership\Tests\Fixtures\Resources\IncrementalChildAdapter;
use Nvl\Ownership\Tests\Fixtures\Resources\IncrementalIndependentAdapter;
use Nvl\Ownership\Tests\Fixtures\Resources\IncrementalRootAdapter;
use Nvl\Ownership\Tests\Fixtures\Resources\StringKeyAsset;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    $schema = DB::getSchemaBuilder();
    $schema->create('ownership_test_column_assets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id');
        $table->string('name');
        $table->foreign('company_id')->references('id')->on('ownership_test_companies');
    });
    $schema->create('ownership_test_child_assets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('asset_id');
        $table->string('name');
        $table->foreign('asset_id')->references('id')->on('ownership_test_column_assets');
    });
    $schema->create('ownership_test_string_key_assets', static function (Blueprint $table): void {
        $table->string('id', 191)->primary();
        $table->unsignedBigInteger('company_id');
        $table->string('name');
        $table->foreign('company_id')->references('id')->on('ownership_test_companies');
    });
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
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
    app()->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $this->allowOwners();
    $this->operation = new OwnershipOperation('incremental-adoption', 'fixture', '1');
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.column', 'host-assets', ColumnAsset::class, ColumnAsset::ownershipDefinition()));
    app(OwnershipAdoptionRegistry::class)->register('host-root', IncrementalRootAdapter::class);
    $this->adopt = function (string $package): string {
        $coordinator = app(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare([$package], [], $this->operation);
        $coordinator->backfill($plan, 100, $this->operation);
        $coordinator->activate($plan, $this->operation);

        return $plan->id;
    };
    $this->root = ($this->adopt)('host-root');
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([...$assignment->attributes(), 'id' => 7, 'name' => 'root']));
    });
});

it('fences admission while a newly declared resource is unadopted and reopens after its own package is adopted', function (string $resource, string $model, string $package, string $adapter): void {
    // A later deployment declares one more owned resource. The registry of this process is not frozen yet.
    $resources = new OwnershipResourceRegistry;
    foreach (app(OwnershipResourceRegistry::class)->all() as $definition) {
        $resources->register($definition);
    }
    $resources->register(new OwnershipResourceDefinition($resource, 'host-assets', $model, $model::ownershipDefinition(), parentResource: $resource === 'assets.child' ? 'assets.column' : null));
    $this->app->instance(OwnershipResourceRegistry::class, $resources);
    foreach ([OwnershipAdoptionCoordinator::class, OwnershipInstallationState::class] as $service) {
        $this->app->forgetInstance($service);
    }
    app(OwnershipAdoptionRegistry::class)->register($package, $adapter);

    expect(fn () => app(OwnershipInstallationState::class)->generations())->toThrow(OwnershipSchemaNotReady::class);

    $added = ($this->adopt)($package);

    $generations = app(OwnershipInstallationState::class)->generations();
    expect($generations)->toHaveCount(2)
        ->and($generations['assets.column'])->toEndWith(':'.$this->root)
        ->and($generations[$resource])->toEndWith(':'.$added)
        ->and(DB::table('nvl_ownership_resource_markers')->where('resource', 'assets.column')->value('generation'))->toBe($this->root)
        ->and(DB::table('ownership_test_column_assets')->count())->toBe(1);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        expect(ColumnAsset::query()->pluck('id')->all())->toBe([7]);
    });
})->with([
    'independent resource' => ['assets.string-key', StringKeyAsset::class, 'host-independent', IncrementalIndependentAdapter::class],
    'child of the active root' => ['assets.child', ChildAsset::class, 'host-children', IncrementalChildAdapter::class],
]);

it('refuses to extend an adapter that already has an active run and keeps the original generation', function (): void {
    $resources = new OwnershipResourceRegistry;
    foreach (app(OwnershipResourceRegistry::class)->all() as $definition) {
        $resources->register($definition);
    }
    $resources->register(new OwnershipResourceDefinition('assets.child', 'host-assets', ChildAsset::class, ChildAsset::ownershipDefinition(), parentResource: 'assets.column'));
    $this->app->instance(OwnershipResourceRegistry::class, $resources);
    foreach ([OwnershipAdoptionCoordinator::class, OwnershipInstallationState::class] as $service) {
        $this->app->forgetInstance($service);
    }
    $registry = new OwnershipAdoptionRegistry;
    $registry->register('host-root', FreshAssetsAdapter::class);
    $this->app->instance(OwnershipAdoptionRegistry::class, $registry);
    $coordinator = app(OwnershipAdoptionCoordinator::class);

    expect(fn () => $coordinator->prepare(['host-root'], [], $this->operation))->toThrow(OwnershipSchemaNotReady::class, 'marker replacement is forbidden')
        ->and(fn () => $coordinator->resume($this->root))->toThrow(OwnershipConfigurationInvalid::class)
        ->and(DB::table('nvl_ownership_adoption_runs')->pluck('status', 'id')->all())->toBe([$this->root => 'active'])
        ->and(DB::table('nvl_ownership_resource_markers')->pluck('generation', 'resource')->all())->toBe(['assets.column' => $this->root]);
});
