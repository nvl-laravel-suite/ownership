<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Container\Container;
use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
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
    /** Register the identical code graph in producers and independent native workers. */
    public static function register(Container $app): void
    {
        $app->make(OwnerRegistry::class)->reference(StringCompany::class, 'ownership-fixture.string-owner');
        foreach (self::models() as $key => $class) {
            $app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition($key, 'assets', $class, $class::ownershipDefinition(), parentResource: $key === 'assets.child' ? 'assets.column' : null, parentResources: $key === 'assets.poly' ? ['assets.column', 'assets.string-key'] : []));
        }
    }

    /** @return array<string, class-string<Model&Ownable>> */
    private static function models(): array
    {
        return ['assets.column' => ColumnAsset::class, 'assets.pivot' => PivotAsset::class, 'assets.captured' => CapturedOperation::class, 'assets.child' => ChildAsset::class, 'assets.string' => StringAsset::class, 'assets.string-key' => StringKeyAsset::class, 'assets.poly' => PolymorphicAsset::class];
    }

    /** Prepare host-native structures and activate through the same protocol as a consumer. */
    public static function install(Container $app): void
    {
        $migration = require __DIR__.'/../../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php';
        if (! is_object($migration) || ! method_exists($migration, 'up')) {
            throw new \RuntimeException('The ownership migration is unavailable.');
        }
        $migration->up();
        $schema = DB::getSchemaBuilder();
        $schema->create('ownership_test_string_companies', static function (Blueprint $table): void {
            $table->string('id', 191)->primary();
            $table->boolean('active');
        });
        DB::table('ownership_test_string_companies')->insert([['id' => '007', 'active' => true], ['id' => '7', 'active' => true]]);
        $stringOwner = $app->make(OwnerRegistry::class)->reference(StringCompany::class, 'ownership-fixture.string-owner');
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
        $schema->create('ownership_test_column_assets', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->string('name');
            $table->foreign('company_id')->references('id')->on('ownership_test_companies')->restrictOnDelete();
        });
        foreach (['ownership_test_pivot_assets', 'ownership_test_captured_operations'] as $table) {
            $schema->create($table, static function (Blueprint $table): void {
                $table->unsignedBigInteger('id')->primary();
                $table->string('name');
                $table->unsignedBigInteger('subject_id')->nullable();
            });
        }
        $schema->create('ownership_test_child_assets', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedBigInteger('asset_id');
            $table->string('name');
            $table->foreign('asset_id')->references('id')->on('ownership_test_column_assets')->cascadeOnDelete();
        });
        $schema->create('ownership_test_asset_owners', static function (Blueprint $table): void {
            $table->unsignedBigInteger('asset_id')->primary();
            $table->unsignedBigInteger('company_id');
            $table->foreign('asset_id')->references('id')->on('ownership_test_pivot_assets')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('ownership_test_companies')->restrictOnDelete();
            $table->index(['company_id', 'asset_id']);
        });
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
        $mappings = [];
        self::register($app);
        foreach (self::models() as $key => $class) {
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
                $owner = $key === 'assets.string' ? $stringOwner::query()->findOrFail($company) : Company::query()->findOrFail($company);
                $mappings[] = new OwnershipMapping($key, (string) $data['id'], $app->make(OwnerIdentityResolver::class)->reference($owner));
            }
        }
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
        $operation = new OwnershipOperation('adopt-assets', 'fixture-operator', '1');
        $coordinator = $app->make(OwnershipAdoptionCoordinator::class);
        $plan = $coordinator->prepare(['assets'], $mappings, $operation);
        $coordinator->backfill($plan, 100, $operation);
        $coordinator->activate($plan, $operation);
    }

    private function __construct() {}
}
