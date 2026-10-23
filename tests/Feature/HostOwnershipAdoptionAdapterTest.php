<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ChildAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\FreshAssetsAdapter;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
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
    $resources = app(OwnershipResourceRegistry::class);
    $resources->register(new OwnershipResourceDefinition('assets.column', 'host-assets', ColumnAsset::class, ColumnAsset::ownershipDefinition()));
    $resources->register(new OwnershipResourceDefinition('assets.child', 'host-assets', ChildAsset::class, ChildAsset::ownershipDefinition(), parentResource: 'assets.column'));
    app(OwnershipAdoptionRegistry::class)->register('host-assets', FreshAssetsAdapter::class);
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
    $this->operation = new OwnershipOperation('fresh-host-adoption', 'fixture', '1');
});

it('adopts an empty host graph using native constraints and enforces owner isolation afterward', function (): void {
    $this->allowOwners();
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['host-assets'], [], $this->operation);
    expect($coordinator->backfill($plan, 100, $this->operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $this->operation);
    $actor = Actor::query()->firstOrFail();
    foreach ([7, 8] as $id) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($id), $actor, function () use ($id): void {
            app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([
                ...$assignment->attributes(), 'id' => $id, 'name' => 'private',
            ]));
            app(OwnershipWriter::class)->create(ChildAsset::class, fn (OwnershipAssignment $assignment): ChildAsset => ChildAsset::query()->create([
                ...$assignment->attributes(), 'id' => $id, 'asset_id' => $id, 'name' => 'child',
            ]));
        });
    }
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function (): void {
        expect(ColumnAsset::query()->pluck('id')->all())->toBe([7])
            ->and(ChildAsset::query()->pluck('id')->all())->toBe([7]);
    });
    $scope = DB::table('ownership_test_column_assets')->where('id', 7)->value('owner_scope_key');
    expect(fn () => DB::table('ownership_test_child_assets')->insert([
        'id' => 9, 'asset_id' => 8, 'name' => 'foreign child', 'owner_scope_key' => $scope,
    ]))->toThrow(QueryException::class);
});

it('refuses fresh host adoption when existing records would require reviewed mappings', function (): void {
    DB::table('ownership_test_column_assets')->insert(['id' => 1, 'company_id' => 7, 'name' => 'existing']);
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['host-assets'], [], $this->operation))
        ->toThrow(OwnershipConfigurationInvalid::class);
    expect(DB::table('nvl_ownership_resource_markers')->where('state', 'active')->exists())->toBeFalse();
});

it('requires fresh host authorization for adoption', function (): void {
    app()->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void
        {
            throw new OwnershipViolation;
        }
    });
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->prepare(['host-assets'], [], $this->operation))
        ->toThrow(OwnershipViolation::class)
        ->and(DB::table('nvl_ownership_adoption_runs')->count())->toBe(0);
});

it('cannot activate a host child whose native parent foreign key is absent', function (): void {
    $schema = DB::getSchemaBuilder();
    $schema->drop('ownership_test_child_assets');
    $schema->create('ownership_test_child_assets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('asset_id');
        $table->string('name');
    });
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['host-assets'], [], $this->operation);
    $coordinator->backfill($plan, 100, $this->operation);
    expect(fn () => $coordinator->activate($plan, $this->operation))
        ->toThrow(OwnershipSchemaNotReady::class)
        ->and(DB::table('nvl_ownership_resource_markers')->where('state', 'active')->exists())->toBeFalse();
});

it('refuses direct adapter calls outside their registered coordinator phase', function (string $phase): void {
    $plan = new OwnershipAdoptionPlan('forged', 'sqlite', 'mapping', 'configuration');
    $adapter = app(FreshAssetsAdapter::class);
    expect(fn () => $phase === 'backfill' ? $adapter->backfill($plan, null, 100) : $adapter->{$phase}($plan))
        ->toThrow(OwnershipViolation::class);
})->with(['prepare', 'backfill', 'verify', 'activate']);
