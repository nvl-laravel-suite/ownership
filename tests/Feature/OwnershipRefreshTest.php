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

it('refuses to save, reload or delete a partially selected owned model with an explicit reason', function (string $class, array $columns, string $missing, string $method): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($class, $columns, $missing, $method): void {
        $model = $class::query()->select($columns)->findOrFail(1);
        $operation = static function () use ($model, $method): mixed {
            if ($method === 'save') {
                $model->setAttribute('name', 'renamed');
            }

            return $model->{$method}();
        };

        expect($operation)->toThrow(OwnershipViolation::class, "loaded without its {$missing}");
        expect($class::query()->findOrFail(1)->getAttribute('name'))->toBe('private-7');
    });
})->with([
    'column owner field' => [ColumnAsset::class, ['id', 'name'], 'protected ownership field [owner_scope_key]'],
    'pivot partition field' => [PivotAsset::class, ['id', 'name'], 'protected ownership field [owner_scope_key]'],
    'primary key' => [ColumnAsset::class, ['name', 'company_id', 'owner_scope_key'], 'primary key [id]'],
])->with(['save', 'refresh', 'fresh', 'refreshForUpdate', 'delete']);
