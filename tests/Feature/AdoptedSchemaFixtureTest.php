<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Ownership\Tests\Fixtures\AdoptedSchemaFixture;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;

it('restores adopted fixture rows and sequence state and discards altered protocol or schema', function (): void {
    $fixture = AdoptedSchemaFixture::start(static::class, true);
    if ($fixture === null) {
        $this->markTestSkipped('Requires runner-owned native schema reuse.');
    }

    ResourceScenario::installColumnAndPivot($this->app);
    Schema::create('ownership_fixture_sequence', static function (Blueprint $table): void {
        $table->id();
        $table->string('label');
    });
    $fixture->observe(ResourceScenario::ColumnAndPivotSeedTables);

    $original = DB::table('ownership_test_column_assets')->where('id', 1)->sole();
    $markerCount = DB::table(Tables::Markers)->count();
    DB::table('ownership_test_column_assets')->where('id', 1)->update(['name' => 'changed']);
    DB::table('ownership_test_pivot_assets')->where('id', 1)->delete();
    expect(DB::table('ownership_fixture_sequence')->insertGetId(['label' => 'first']))->toBe(1);
    DB::table('ownership_fixture_sequence')->where('id', 1)->delete();
    $fixture->reset();

    $next = AdoptedSchemaFixture::start(static::class, true);
    expect($next->reused)->toBeTrue()
        ->and(DB::table(Tables::Markers)->count())->toBe($markerCount);
    Company::query()->create(['id' => 7]);
    Company::query()->create(['id' => 8]);
    $next->observe(ResourceScenario::ColumnAndPivotSeedTables);

    expect((array) DB::table('ownership_test_column_assets')->where('id', 1)->sole())->toBe((array) $original)
        ->and(DB::table('ownership_test_asset_owners')->count())->toBe(2)
        ->and(DB::table('ownership_fixture_sequence')->count())->toBe(0)
        ->and(DB::table('ownership_fixture_sequence')->insertGetId(['label' => 'second']))->toBe(1);

    Schema::drop('ownership_fixture_sequence');
    $next->reset();

    $protocol = AdoptedSchemaFixture::start(static::class, true);
    expect($protocol->reused)->toBeFalse('Schema changes must invalidate the retained fixture.')
        ->and(DB::table(Tables::Markers)->count())->toBe($markerCount);
    $protocol->observe(ResourceScenario::ColumnAndPivotSeedTables);
    DB::table(Tables::Markers)->delete();
    $protocol->reset();

    expect(AdoptedSchemaFixture::start(static::class, true)->reused)->toBeFalse('Protocol writes must invalidate the retained fixture.');
})->group('adopted-schema-fixture');
