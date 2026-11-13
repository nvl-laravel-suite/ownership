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
use Nvl\Ownership\Tests\Fixtures\Resources\CapturedOperation;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OptsIntoAdoptedSchemaReuse::class);

beforeEach(function (): void {
    $this->allowOwners();
    $this->installNativeRootScenario(['assets.column', 'assets.pivot', 'assets.captured', 'assets.child']);
});

it('rejects raw primary owner changes and ordinary pivot attach detach and update conveniences', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $asset = PivotAsset::query()->findOrFail(1);
        expect(fn () => $asset->companies()->detach())->toThrow(QueryException::class)
            ->and(fn () => $asset->companies()->attach(8, ['owner_scope_key' => $asset->getRawOriginal('owner_scope_key')]))->toThrow(QueryException::class)
            ->and(fn () => $asset->companies()->updateExistingPivot(7, ['company_id' => 8]))->toThrow(QueryException::class)
            ->and(fn () => DB::table('ownership_test_column_assets')->where('id', 1)->update(['company_id' => 8]))->toThrow(QueryException::class);
        app(OwnershipBoundary::class)->assertRecord($asset, 'assets.pivot');
    });
});

it('rejects null and invalid physical partitions and a mismatched concrete child partition', function (): void {
    $scope = DB::table('ownership_test_column_assets')->where('id', 1)->value('owner_scope_key');
    expect(fn () => DB::table('ownership_test_column_assets')->insert(['id' => 3, 'company_id' => 7, 'name' => 'null', 'owner_scope_key' => null]))->toThrow(QueryException::class)
        ->and(fn () => DB::table('ownership_test_column_assets')->insert(['id' => 3, 'company_id' => 7, 'name' => 'invalid', 'owner_scope_key' => 'owner:invalid']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('ownership_test_child_assets')->insert(['id' => 3, 'asset_id' => 2, 'name' => 'foreign-child', 'owner_scope_key' => $scope]))->toThrow(QueryException::class);
});

it('does not trust a loaded pivot relation and retains source cascade deletion', function (): void {
    $foreign = PivotAsset::withoutGlobalScopes()->findOrFail(2);
    $foreign->setRelation('companies', Company::query()->whereKey(7)->get());
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($foreign): void {
        expect(fn () => app(OwnershipBoundary::class)->assertRecord($foreign, 'assets.pivot'))->toThrow(OwnershipViolation::class);
        PivotAsset::query()->findOrFail(1)->delete();
        expect(DB::table('ownership_test_asset_owners')->where('asset_id', 1)->exists())->toBeFalse()
            ->and(DB::table('ownership_test_asset_owners')->where('asset_id', 2)->exists())->toBeTrue();
    });
});

it('denies a missing primary pivot and never uses its loaded owner collection as a fallback', function (): void {
    $connection = DB::connection();
    $name = 'nvl_own_'.substr(hash('sha256', 'ownership_test_asset_owners|delete|primary'), 0, 32);
    $statement = 'DROP TRIGGER '.$connection->getQueryGrammar()->wrap($name);
    if ($connection->getDriverName() === 'pgsql') {
        $statement .= ' ON ownership_test_asset_owners';
    }
    $connection->statement($statement);
    $connection->table('ownership_test_asset_owners')->where('asset_id', 1)->delete();
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        expect(fn () => PivotAsset::query()->get())->toThrow(OwnershipSchemaNotReady::class);
    });
})->group('fresh-adoption');

it('rejects foreign instance counters before observers or native SQL including quiet mutation', function (): void {
    DB::table('ownership_test_captured_operations')->where('id', 2)->update(['subject_id' => 0]);
    $foreign = CapturedOperation::withoutGlobalScopes()->findOrFail(2);
    $observed = 0;
    CapturedOperation::updating(function () use (&$observed): void {
        $observed++;
    });
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::firstOrFail(), function () use ($foreign): void {
        expect(fn () => $foreign->increment('subject_id'))->toThrow(OwnershipViolation::class)
            ->and(fn () => $foreign->decrementQuietly('subject_id'))->toThrow(OwnershipViolation::class);
    });
    expect($observed)->toBe(0)
        ->and(DB::table('ownership_test_captured_operations')->where('id', 2)->value('subject_id'))->toBe(0);
});
