<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\GuardedAssetBuilder;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipScope;

uses(OptsIntoAdoptedSchemaReuse::class);

beforeEach(function (): void {
    $this->allowOwners();
    $this->installColumnScenario();
});

/** @return array<int, int> */
function columnAssetOwners(): array
{
    return DB::table('ownership_test_column_assets')->orderBy('id')->pluck('company_id', 'id')->all();
}

function asColumnAssetOwner(int $company, Closure $work): mixed
{
    return app(OwnershipRunner::class)->run(Company::query()->findOrFail($company), Actor::query()->firstOrFail(), $work);
}

it('keeps a scope-free builder force delete inside the admitted owner', function (): void {
    expect(columnAssetOwners())->toBe([1 => 7, 2 => 8]);

    $deleted = asColumnAssetOwner(7, static fn (): mixed => ColumnAsset::query()->forceDelete());

    expect($deleted)->toBe(1)
        ->and(columnAssetOwners())->toBe([2 => 8]);
});

it('groups caller alternatives before applying the owner boundary to a force delete', function (): void {
    $deleted = asColumnAssetOwner(7, static fn (): mixed => ColumnAsset::query()->where('id', 1)->orWhere('id', 2)->forceDelete());

    expect($deleted)->toBe(1)
        ->and(columnAssetOwners())->toBe([2 => 8]);
});

it('refuses a builder force delete without an admitted owner', function (): void {
    expect(static fn (): mixed => ColumnAsset::query()->forceDelete())->toThrow(OwnershipViolation::class)
        ->and(columnAssetOwners())->toBe([1 => 7, 2 => 8]);
});

it('cannot update another owner through a forwarded update-or-insert', function (): void {
    expect(static fn (): mixed => asColumnAssetOwner(7, static fn (): mixed => ColumnAsset::query()->updateOrInsert(['id' => 2], ['name' => 'taken'])))
        ->toThrow(QueryException::class)
        ->and(DB::table('ownership_test_column_assets')->where('id', 2)->value('name'))->not->toBe('taken');

    asColumnAssetOwner(7, static fn (): mixed => ColumnAsset::query()->updateOrInsert(['id' => 1], ['name' => 'renamed']));

    expect(DB::table('ownership_test_column_assets')->where('id', 1)->value('name'))->toBe('renamed');
});

it('guards a custom builder that adopts the owned write trait', function (): void {
    $deleted = asColumnAssetOwner(7, static function (): mixed {
        $model = new ColumnAsset;
        $builder = $model->registerGlobalScopes((new GuardedAssetBuilder($model->getConnection()->query()))->setModel($model));

        return $builder->forceDelete();
    });

    expect($deleted)->toBe(1)
        ->and(columnAssetOwners())->toBe([2 => 8]);
});

it('leaves an explicitly unscoped builder native', function (): void {
    $deleted = asColumnAssetOwner(7, static fn (): mixed => ColumnAsset::query()->withoutGlobalScope(OwnershipScope::class)->where('id', 2)->forceDelete());

    expect($deleted)->toBe(1)
        ->and(columnAssetOwners())->toBe([1 => 7]);
});
