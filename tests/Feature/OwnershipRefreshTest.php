<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OptsIntoAdoptedSchemaReuse::class);

beforeEach(function (): void {
    $this->allowOwners();
    $this->installColumnScenario(withPivot: true);
});

it('keeps native refresh APIs inside the current owner for held column and pivot models', function (string $class): void {
    foreach (['fresh', 'refresh', 'refreshForUpdate'] as $method) {
        $model = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($class, $method) {
            $model = $class::query()->findOrFail(1);
            expect($model->{$method}()->getKey())->toBe(1, $method);

            return $model;
        });
        app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->findOrFail(1), function () use ($model, $method): void {
            expect(fn () => $model->{$method}())->toThrow(OwnershipViolation::class, null, $method);
        });
    }
})->with([
    'column model' => [ColumnAsset::class],
    'pivot model' => [PivotAsset::class],
]);

it('refuses to save, reload or delete a partially selected owned model with an explicit reason', function (string $class, array $columns, string $missing): void {
    foreach (['save', 'refresh', 'fresh', 'refreshForUpdate', 'delete'] as $method) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->findOrFail(1), function () use ($class, $columns, $missing, $method): void {
            $model = $class::query()->select($columns)->findOrFail(1);
            if ($method === 'save') {
                $model->setAttribute('name', 'renamed');
            }

            expect(fn () => $model->{$method}())->toThrow(OwnershipViolation::class, "loaded without its {$missing}", $method);
            expect($class::query()->findOrFail(1)->getAttribute('name'))->toBe('private-7', $method);
        });
    }
})->with([
    'column owner field' => [ColumnAsset::class, ['id', 'name'], 'protected ownership field [owner_scope_key]'],
    'pivot partition field' => [PivotAsset::class, ['id', 'name'], 'protected ownership field [owner_scope_key]'],
    'primary key' => [ColumnAsset::class, ['name', 'company_id', 'owner_scope_key'], 'primary key [id]'],
]);
