<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Installs a small real adopted graph; no test fabricates readiness markers. */
final readonly class ResourceScenario
{
    public const array ColumnSeedTables = ['ownership_test_column_assets'];

    public const array ColumnAndPivotSeedTables = [
        ...self::ColumnSeedTables, 'ownership_test_pivot_assets', 'ownership_test_asset_owners',
    ];

    private const array NativeRootTables = [
        'assets.column' => self::ColumnSeedTables,
        'assets.pivot' => ['ownership_test_pivot_assets', 'ownership_test_asset_owners'],
        'assets.captured' => ['ownership_test_captured_operations'],
        'assets.child' => ['ownership_test_child_assets'],
    ];

    /** Tables with fixed rows after adoption, in foreign-key insertion order. */
    public const array SeedTables = [
        'ownership_test_string_companies',
        'ownership_test_string_assets',
        'ownership_test_column_assets',
        'ownership_test_pivot_assets',
        'ownership_test_captured_operations',
        'ownership_test_child_assets',
        'ownership_test_asset_owners',
        'ownership_test_string_key_assets',
        'ownership_test_polymorphic_assets',
    ];

    /** Register the identical code graph in producers and independent native workers. */
    public static function register(Container $app): void
    {
        $app->make(OwnerRegistry::class)->reference(StringCompany::class, 'ownership-fixture.string-owner');
        self::registerModels($app, self::models());
    }

    /** @param array<string, class-string<Model&Ownable>> $models */
    private static function registerModels(Container $app, array $models): void
    {
        foreach ($models as $key => $class) {
            $app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition($key, 'assets', $class, $class::ownershipDefinition(), parentResource: $key === 'assets.child' ? 'assets.column' : null, parentResources: $key === 'assets.poly' ? ['assets.column', 'assets.string-key'] : []));
        }
    }

    /** @return array<string, class-string<Model&Ownable>> */
    private static function models(): array
    {
        return ['assets.column' => ColumnAsset::class, 'assets.pivot' => PivotAsset::class, 'assets.captured' => CapturedOperation::class, 'assets.child' => ChildAsset::class, 'assets.string' => StringAsset::class, 'assets.string-key' => StringKeyAsset::class, 'assets.poly' => PolymorphicAsset::class];
    }

    /** Adopt a native column resource for tests that need only this model. */
    public static function installColumn(Container $app, bool $reused = false): void
    {
        self::installNativeRoots($app, ['assets.column'], $reused);
    }

    /** Adopt the two resource strategies needed by admission query tests. */
    public static function installColumnAndPivot(Container $app, bool $reused = false): void
    {
        self::installNativeRoots($app, ['assets.column', 'assets.pivot'], $reused);
    }

    /**
     * Install only the native resources a runtime case exercises.
     *
     * @param  list<string>  $resources
     * @return list<string> Fixed seed tables in insertion order.
     */
    public static function installNativeRoots(Container $app, array $resources, bool $reused = false): array
    {
        if (! in_array('assets.column', $resources, true) || array_diff($resources, array_keys(self::NativeRootTables)) !== []) {
            throw new LogicException('Native root fixtures require the column root and supported dependent resources.');
        }
        $selected = array_fill_keys($resources, true);
        $models = array_intersect_key(self::models(), $selected);
        $seeds = array_merge(...array_values(array_intersect_key(self::NativeRootTables, $selected)));
        self::registerModels($app, $models);
        if ($reused) {
            self::bindAdoption($app, array_keys($models));

            return $seeds;
        }
        self::migrate();
        self::createColumnTable();
        if (isset($models['assets.pivot'])) {
            self::createPivotTables();
        }
        if (isset($models['assets.captured'])) {
            self::createCapturedTable();
        }
        if (isset($models['assets.child'])) {
            self::createChildTable();
        }
        self::adopt($app, $models);

        return $seeds;
    }

    /** Prepare host-native structures and activate through the same protocol as a consumer. */
    public static function install(Container $app, bool $reused = false): void
    {
        if ($reused) {
            self::register($app);
            self::bindAdoption($app, array_keys(self::models()));

            return;
        }

        self::migrate();
        $schema = DB::getSchemaBuilder();
        $schema->create('ownership_test_string_companies', static function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->boolean('active');
        });
        DB::table('ownership_test_string_companies')->insert([['id' => '007', 'active' => true], ['id' => '7', 'active' => true]]);
        $schema->create('ownership_test_string_assets', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            if (config('ownership-test.lossy-column') === true) {
                $table->unsignedBigInteger('company_key');
            } else {
                $table->string('company_key', 191);
            }
            $table->string('name');
            $table->foreign('company_key')->references('id')->on('ownership_test_string_companies')->restrictOnDelete();
        });
        self::createColumnTable();
        self::createPivotTables();
        self::createCapturedTable();
        self::createChildTable();
        $schema->create('ownership_test_string_key_assets', static function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->foreign('company_id')->references('id')->on('ownership_test_companies')->restrictOnDelete();
        });
        $schema->create('ownership_test_polymorphic_assets', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('subject_type');
            $table->string('subject_id', 191);
            $table->string('name');
        });
        self::register($app);
        self::adopt($app, self::models());
    }

    private static function migrate(): void
    {
        $migration = require __DIR__.'/../../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php';
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new \RuntimeException('The ownership migration is unavailable.');
        }
        $migration->up();
    }

    private static function createCapturedTable(): void
    {
        DB::getSchemaBuilder()->create('ownership_test_captured_operations', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('subject_id')->nullable();
        });
    }

    private static function createChildTable(): void
    {
        DB::getSchemaBuilder()->create('ownership_test_child_assets', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('asset_id');
            $table->string('name');
            $table->foreign('asset_id')->references('id')->on('ownership_test_column_assets')->cascadeOnDelete();
        });
    }

    private static function createColumnTable(): void
    {
        DB::getSchemaBuilder()->create('ownership_test_column_assets', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->foreign('company_id')->references('id')->on('ownership_test_companies')->restrictOnDelete();
        });
    }

    private static function createPivotTables(): void
    {
        $schema = DB::getSchemaBuilder();
        $schema->create('ownership_test_pivot_assets', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
            $table->unsignedBigInteger('subject_id')->nullable();
        });
        $schema->create('ownership_test_asset_owners', static function (Blueprint $table): void {
            $table->unsignedBigInteger('asset_id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->foreign('asset_id')->references('id')->on('ownership_test_pivot_assets')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('ownership_test_companies')->restrictOnDelete();
            $table->index(['company_id', 'asset_id']);
        });
    }

    /** @param array<string, class-string<Model&Ownable>> $models */
    private static function adopt(Container $app, array $models): void
    {
        $mappings = [];
        foreach ($models as $key => $class) {
            $ownerClass = $key === 'assets.string'
                ? $app->make(OwnerRegistry::class)->reference(StringCompany::class, 'ownership-fixture.string-owner')
                : Company::class;
            foreach ($key === 'assets.string' ? [1 => '007', 2 => '7'] : [1 => 7, 2 => 8] as $id => $company) {
                $data = ['id' => $id, 'name' => 'private-'.$company];
                if ($key === 'assets.string-key') {
                    $data['id'] = $id === 1 ? '007' : '7';
                    $data['company_id'] = $company;
                } elseif ($key === 'assets.poly') {
                    $data['subject_type'] = StringKeyAsset::class;
                    $data['subject_id'] = $id === 1 ? '007' : '7';
                }
                if ($key === 'assets.column') {
                    $data['company_id'] = $company;
                } elseif ($key === 'assets.child') {
                    $data['asset_id'] = $id;
                } elseif ($key === 'assets.string') {
                    $data['company_key'] = $company;
                }
                DB::table((new $class)->getTable())->insert($data);
                $owner = $ownerClass::query()->findOrFail($company);
                $mappings[] = new OwnershipMapping($key, (string) $data['id'], $app->make(OwnerIdentityResolver::class)->reference($owner));
            }
        }
        self::bindAdoption($app, array_keys($models));
        $operation = new OwnershipOperation('adopt-assets', 'fixture-operator', '1');
        $coordinator = $app->make(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['assets'], $mappings, $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }

    /** @param list<string> $resourceKeys */
    private static function bindAdoption(Container $app, array $resourceKeys): void
    {
        $app->when(AssetsAdapter::class)->needs('$resourceKeys')->give($resourceKeys);
        $app->make(OwnershipAdoptionRegistry::class)->register('assets', AssetsAdapter::class);
        $app->bind(OwnershipAdoptionAccess::class, static fn (): OwnershipAdoptionAccess => new class implements OwnershipAdoptionAccess
        {
            /** The fixture represents a separately authorized maintenance operator. */
            public function authorize(OwnershipOperation $operation): void {}
        });
        $app->instance(MaintenanceMode::class, new class implements MaintenanceMode
        {
            /** @param array<string, mixed> $payload */
            public function activate(array $payload): void {}

            /** Restore the fixture maintenance flag. */
            public function deactivate(): void {}

            /** Freeze all normal producers for adoption. */
            public function active(): bool
            {
                return true;
            }

            /** @return array<string, mixed> */
            public function data(): array
            {
                return [];
            }
        });
    }

    private function __construct() {}
}
