<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipDatabaseConstraints;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\SchemaQualifiedAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\SchemaQualifiedAssetsAdapter;
use Nvl\Ownership\Tests\Fixtures\Resources\SchemaQualifiedChildAsset;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipConstraintInspector;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    if (DB::connection()->getDriverName() !== 'pgsql') {
        $this->markTestSkipped('Schema-qualified model tables are a PostgreSQL feature.');
    }
    DB::statement('DROP SCHEMA IF EXISTS ownership_tenant CASCADE');
    DB::statement('CREATE SCHEMA ownership_tenant');
});

afterEach(function (): void {
    if (DB::connection()->getDriverName() === 'pgsql') {
        DB::statement('DROP SCHEMA IF EXISTS ownership_tenant CASCADE');
    }
});

it('adopts and enforces resources whose model tables name a PostgreSQL schema', function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    $schema = DB::getSchemaBuilder();
    $schema->create('ownership_tenant.documents', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id');
        $table->string('name');
        $table->foreign('company_id')->references('id')->on('ownership_test_companies');
    });
    $schema->create('ownership_tenant.document_lines', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('document_id');
        $table->string('name');
        $table->foreign('document_id')->references('id')->on('ownership_tenant.documents');
    });
    $resources = app(OwnershipResourceRegistry::class);
    $resources->register(new OwnershipResourceDefinition('tenant.documents', 'tenant-assets', SchemaQualifiedAsset::class, SchemaQualifiedAsset::ownershipDefinition()));
    $resources->register(new OwnershipResourceDefinition('tenant.lines', 'tenant-assets', SchemaQualifiedChildAsset::class, SchemaQualifiedChildAsset::ownershipDefinition(), parentResource: 'tenant.documents'));
    app(OwnershipAdoptionRegistry::class)->register('tenant-assets', SchemaQualifiedAssetsAdapter::class);
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
    $operation = new OwnershipOperation('schema-qualified-adoption', 'fixture', '1');
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['tenant-assets'], [], $operation);
    expect($coordinator->backfill($plan, 100, $operation))->toBeTrue()
        ->and($coordinator->verify($plan)->passed())->toBeTrue();
    $coordinator->activate($plan, $operation);
    app(OwnershipSchema::class)->assertReady('tenant.lines');

    $actor = Actor::query()->firstOrFail();
    foreach ([7, 8] as $id) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($id), $actor, function () use ($id): void {
            app(OwnershipWriter::class)->create(SchemaQualifiedAsset::class, fn (OwnershipAssignment $assignment): SchemaQualifiedAsset => SchemaQualifiedAsset::query()->create([
                ...$assignment->attributes(), 'id' => $id, 'name' => 'private',
            ]));
            app(OwnershipWriter::class)->create(SchemaQualifiedChildAsset::class, fn (OwnershipAssignment $assignment): SchemaQualifiedChildAsset => SchemaQualifiedChildAsset::query()->create([
                ...$assignment->attributes(), 'id' => $id, 'document_id' => $id, 'name' => 'line',
            ]));
        });
    }
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), $actor, function (): void {
        expect(SchemaQualifiedAsset::query()->pluck('id')->all())->toBe([7])
            ->and(SchemaQualifiedChildAsset::query()->pluck('id')->all())->toBe([7]);
    });
    $scope = DB::table('ownership_tenant.documents')->where('id', 7)->value('owner_scope_key');
    expect(fn () => DB::table('ownership_tenant.document_lines')->insert(['id' => 9, 'document_id' => 8, 'name' => 'foreign', 'owner_scope_key' => $scope]))
        ->toThrow(QueryException::class);
});

it('inspects protocol triggers and indexes of a schema-qualified table on a prefixed connection', function (): void {
    config(['database.connections.prefixed' => [...DB::connection()->getConfig(), 'prefix' => 'p_', 'name' => 'prefixed']]);
    $connection = DB::connection('prefixed');
    $connection->getSchemaBuilder()->create('ownership_tenant.parents', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('owner_scope_key', 191)->nullable();
        $table->unique(['id', 'owner_scope_key']);
    });
    $connection->getSchemaBuilder()->create('ownership_tenant.children', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('parent_id');
        $table->string('owner_scope_key', 191)->nullable();
    });
    $constraints = app(OwnershipDatabaseConstraints::class);
    $inspector = app(OwnershipConstraintInspector::class);
    $constraints->protect($connection, 'ownership_tenant.parents', ['id', 'owner_scope_key'], false);
    $constraints->reference($connection, 'ownership_tenant.children', 'parent_id', 'ownership_tenant.parents', 'id');

    $constraints->assertProtected($connection, 'ownership_tenant.parents');
    $constraints->assertReference($connection, 'ownership_tenant.children', 'parent_id', 'ownership_tenant.parents', 'id');
    $inspector->assertPresent($connection, 'ownership_tenant.parents', 'insert', 'valid');
    $inspector->assertUniqueIndexes($connection, 'ownership_tenant.parents', [['id', 'owner_scope_key']]);
    expect($connection->selectOne("select to_regclass('ownership_tenant.p_parents') as oid")->oid)->not->toBeNull()
        ->and(fn () => $constraints->assertProtected($connection, 'ownership_tenant.children'))->toThrow(OwnershipSchemaNotReady::class);
});
