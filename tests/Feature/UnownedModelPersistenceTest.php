<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Tests\Fixtures\Resources\UnownedAsset;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

it('keeps unowned persistence native with the optional runtime enabled or disabled', function (bool $enabled, bool $registered): void {
    config(['nvl-ownership.enabled' => $enabled]);
    DB::getSchemaBuilder()->create('ownership_test_unowned_assets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('name');
    });
    if ($registered) {
        app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.unowned', 'assets', UnownedAsset::class, UnownedAsset::ownershipDefinition()));
    }
    $level = null;
    UnownedAsset::saving(static function () use (&$level): void {
        $level = DB::transactionLevel();
    });
    $model = UnownedAsset::query()->create(['id' => 1, 'name' => 'native']);
    expect($model->getKey())->toBe(1)
        ->and($level)->toBe(0)
        ->and($model->deleteQuietly())->toBeTrue()
        ->and(UnownedAsset::query()->count())->toBe(0);
})->with([[false, false], [false, true], [true, false], [true, true]]);
