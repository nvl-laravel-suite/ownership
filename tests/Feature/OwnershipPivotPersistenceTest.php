<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Adoption\OwnedMorphPivotRecord;
use Nvl\Ownership\Tests\Fixtures\Adoption\OwnedPivotRecord;
use Nvl\Ownership\Tests\Fixtures\Adoption\RecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    $this->allowOwners();
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    DB::getSchemaBuilder()->create('ownership_test_records', static function (Blueprint $table): void {
        $table->uuid('id')->primary();
        $table->unsignedBigInteger('company_id')->nullable();
        $table->json('owner_reference')->nullable();
        $table->foreign('company_id')->references('id')->on('ownership_test_companies')->restrictOnDelete();
    });
    DB::table('ownership_test_records')->insert([
        ['id' => '550e8400-e29b-41d4-a716-446655440007', 'company_id' => 7],
        ['id' => '550e8400-e29b-41d4-a716-446655440008', 'company_id' => 8],
    ]);
    app()->bind(OwnershipAdoptionAccess::class, static fn (): OwnershipAdoptionAccess => new class implements OwnershipAdoptionAccess
    {
        /** Represent the separately authorized fixture maintenance operator. */
        public function authorize(OwnershipOperation $operation): void {}
    });
    app()->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        /** @param array<string, mixed> $payload */
        public function activate(array $payload): void {}

        public function deactivate(): void {}

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
});

dataset('owned native pivots', [
    'pivot' => [OwnedPivotRecord::class],
    'morph pivot' => [OwnedMorphPivotRecord::class],
]);

/** @param class-string<Pivot&Ownable> $class */
function adoptOwnedNativePivot(string $class): void
{
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.records', 'fixture', $class, $class::ownershipDefinition()));
    app(OwnershipAdoptionRegistry::class)->register('fixture', RecordsAdapter::class);
    config([
        'ownership_test.callback' => static fn () => app(OwnershipSchema::class)->prepare('fixture.records'),
        'ownership_test.activate_callback' => static fn () => app(OwnershipSchema::class)->constrain('fixture.records'),
    ]);
    $mappings = [];
    foreach ([7, 8] as $company) {
        $mappings[] = new OwnershipMapping('fixture.records', '550e8400-e29b-41d4-a716-44665544000'.$company, app(OwnerIdentityResolver::class)->reference(Company::findOrFail($company)));
    }
    $operation = new OwnershipOperation('adopt-native-pivots', 'operator', '1');
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture'], $mappings, $operation);
    $coordinator->backfill($plan, 100, $operation);
    $coordinator->activate($plan, $operation);
}

it('retains integer cancellation and successful deletion through the owned write lease', function (string $class, string $method): void {
    adoptOwnedNativePivot($class);
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::firstOrFail(), function () use ($class, $method): void {
        $pivot = $class::findOrFail('550e8400-e29b-41d4-a716-446655440007');
        $cancel = true;
        $class::deleting(static function () use (&$cancel): bool {
            return ! $cancel;
        });
        expect($pivot->delete())->toBe(0)
            ->and($pivot->exists)->toBeTrue();
        $cancel = false;
        expect($pivot->{$method}())->toBe(1)
            ->and($pivot->exists)->toBeFalse();
    });
    expect(DB::table('ownership_test_records')->where('company_id', 7)->exists())->toBeFalse()
        ->and(DB::table('ownership_test_records')->where('company_id', 8)->exists())->toBeTrue();
})->with('owned native pivots')->with(['delete', 'deleteQuietly', 'deleteOrFail']);

it('denies foreign pivot deletion before observers and native SQL even when quiet', function (string $class): void {
    adoptOwnedNativePivot($class);
    $foreign = $class::withoutGlobalScopes()->findOrFail('550e8400-e29b-41d4-a716-446655440008');
    $observed = false;
    $class::deleting(function () use (&$observed): void {
        $observed = true;
    });
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::firstOrFail(), function () use ($foreign): void {
        expect(fn () => $foreign->delete())->toThrow(OwnershipViolation::class)
            ->and(fn () => $foreign->deleteQuietly())->toThrow(OwnershipViolation::class);
    });
    expect($observed)->toBeFalse()
        ->and(DB::table('ownership_test_records')->where('company_id', 8)->exists())->toBeTrue();
})->with('owned native pivots');

it('retains the enclosing transaction rollback after a successful owned pivot deletion', function (string $class): void {
    adoptOwnedNativePivot($class);
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::firstOrFail(), function () use ($class): void {
        expect(fn () => DB::transaction(function () use ($class): void {
            expect($class::findOrFail('550e8400-e29b-41d4-a716-446655440007')->delete())->toBe(1);
            throw new RuntimeException('Host operation failed.');
        }))->toThrow(RuntimeException::class, 'Host operation failed.');
        expect($class::query()->count())->toBe(1);
    });
    expect(DB::transactionLevel())->toBe(0);
})->with('owned native pivots');
