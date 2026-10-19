<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
});

it('keeps native refresh APIs inside the current owner for held column and pivot models', function (string $class, string $method): void {
    $model = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($class, $method) {
        $model = $class::query()->findOrFail(1);
        expect($model->{$method}()->getKey())->toBe(1);

        return $model;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($model, $method): void {
        expect(fn () => $model->{$method}())->toThrow(OwnershipViolation::class);
    });
})->with([
    [ColumnAsset::class, 'fresh'], [ColumnAsset::class, 'refresh'], [ColumnAsset::class, 'refreshForUpdate'],
    [PivotAsset::class, 'fresh'], [PivotAsset::class, 'refresh'], [PivotAsset::class, 'refreshForUpdate'],
]);
