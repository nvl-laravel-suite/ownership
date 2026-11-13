<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipAdoptionScope;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Tests\Fixtures\Resources\AssetsAdapter;
use Nvl\Ownership\Tests\Fixtures\Resources\ScopeEncodingAsset;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    DB::connection()->getSchemaBuilder()->create('ownership_test_scope_encoding', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('scope', 100)->default('default');
        $table->string('subject_type', 255)->default('host');
        $table->string('subject_id', 255)->default('subject');
    });
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.encoding', 'assets', ScopeEncodingAsset::class, ScopeEncodingAsset::ownershipDefinition(), allowsCentral: true));
});

it('keeps full polymorphic ownership uniqueness in native indexes', function (): void {
    $connection = DB::connection();
    $scope = app(OwnershipAdoptionScope::class);
    $scope->during('scope-encoding', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding'));
    $connection->getSchemaBuilder()->table('ownership_test_scope_encoding', static fn (Blueprint $table) => $table->unique(['owner_scope_key', 'scope', 'subject_type', 'subject_id'], 'scope_encoding_full_identity_unique'));
    $scope->during('scope-encoding', AssetsAdapter::class, 'activate', static fn () => app(OwnershipSchema::class)->constrain('assets.encoding'));
    $column = array_find($connection->getSchemaBuilder()->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');

    expect(fn () => $connection->table('ownership_test_scope_encoding')->insert(['id' => 1, 'owner_scope_key' => null]))->toThrow(QueryException::class);
    if ($connection->getDriverName() !== 'sqlite') {
        expect($column['nullable'])->toBeFalse();
    }
    $index = array_find($connection->getSchemaBuilder()->getIndexes('ownership_test_scope_encoding'), static fn (array $index): bool => $index['name'] === 'scope_encoding_full_identity_unique');
    expect($index['columns'])->toBe(['owner_scope_key', 'scope', 'subject_type', 'subject_id']);
});

it('preserves the declared scope default while activating required native columns', function (): void {
    $connection = DB::connection();
    $connection->getSchemaBuilder()->table('ownership_test_scope_encoding', static fn (Blueprint $table) => $table->string('owner_scope_key', 191)->nullable()->default('central'));
    $scope = app(OwnershipAdoptionScope::class);
    $scope->during('scope-default', AssetsAdapter::class, 'prepare', static fn () => app(OwnershipSchema::class)->prepare('assets.encoding'));
    $scope->during('scope-default', AssetsAdapter::class, 'activate', static fn () => app(OwnershipSchema::class)->constrain('assets.encoding'));
    $connection->table('ownership_test_scope_encoding')->insert(['id' => 1]);

    expect($connection->table('ownership_test_scope_encoding')->value('owner_scope_key'))->toBe('central');
});

it('preserves generated scope columns until a reviewed migration replaces them', function (string $phase): void {
    $connection = DB::connection();
    if ($phase === 'activate' && $connection->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite does not rewrite the partition column during activation.');
    }
    $schema = $connection->getSchemaBuilder();
    $schema->table('ownership_test_scope_encoding', static function (Blueprint $table) use ($connection): void {
        $column = $table->string('owner_scope_key', 191)->nullable();
        if ($connection->getDriverName() === 'sqlite') {
            $column->virtualAs("'central'");
        } else {
            $column->storedAs("'central'");
        }
        $table->json('owner_reference')->nullable();
        $table->unique(['id', 'owner_scope_key'], 'scope_generated_identity_unique');
    });
    $before = array_find($schema->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
    expect($before['generation']['type'])->toBe($connection->getDriverName() === 'sqlite' ? 'virtual' : 'stored')
        ->and($before['nullable'])->toBeTrue();

    expect(fn () => app(OwnershipAdoptionScope::class)->during('scope-generated', AssetsAdapter::class, $phase, static fn () => $phase === 'prepare'
        ? app(OwnershipSchema::class)->prepare('assets.encoding')
        : app(OwnershipSchema::class)->constrain('assets.encoding')))
        ->toThrow(OwnershipConfigurationInvalid::class, 'reviewed migration');
    $after = array_find($schema->getColumns('ownership_test_scope_encoding'), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
    expect($after['generation'])->toBe($before['generation'])->and($after['nullable'])->toBe($before['nullable']);
})->with(['prepare', 'activate']);
