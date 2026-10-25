<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\CapturedOperation;
use Nvl\Ownership\Tests\Fixtures\Resources\ChildAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Ownership\Tests\Fixtures\Resources\StringAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\StringCompany;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
});

it('scopes column pivot inherited and captured resources to the full admitted native owner', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        foreach (['assets.column' => ColumnAsset::class, 'assets.pivot' => PivotAsset::class, 'assets.child' => ChildAsset::class, 'assets.captured' => CapturedOperation::class] as $resource => $class) {
            expect(app(OwnershipBoundary::class)->query($class::query(), $resource)->pluck('name')->all())->toBe(['private-7']);
        }
    });
});

it('preserves caller OR predicates while always intersecting canonical ownership', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $query = ColumnAsset::query()->where('name', 'private-8')->orWhere('name', 'private-7');
        expect(app(OwnershipBoundary::class)->query($query, 'assets.column')->pluck('id')->all())->toBe([1])
            ->and(ColumnAsset::query()->where('id', 2)->orWhere('id', 1)->pluck('id')->all())->toBe([1]);
    });
});

it('rejects dirty owner fields forged loaded parents and altered canonical storage', function (): void {
    $foreign = ColumnAsset::withoutGlobalScopes()->findOrFail(2);
    $child = ChildAsset::withoutGlobalScopes()->findOrFail(2);
    $child->setRelation('asset', ColumnAsset::withoutGlobalScopes()->findOrFail(1));
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($foreign, $child): void {
        expect(fn () => app(OwnershipBoundary::class)->assertRecord($foreign, 'assets.column'))->toThrow(OwnershipViolation::class)
            ->and(fn () => app(OwnershipBoundary::class)->assertRecord($child, 'assets.child'))->toThrow(OwnershipViolation::class);
        $own = ColumnAsset::query()->findOrFail(1);
        $own->forceFill(['company_id' => 8]);
        expect(fn () => app(OwnershipBoundary::class)->assertRecord($own, 'assets.column'))->toThrow(OwnershipViolation::class);
        $own->setTable('ownership_test_pivot_assets');
        expect(fn () => app(OwnershipBoundary::class)->assertRecord($own, 'assets.column'))->toThrow(OwnershipViolation::class)
            ->and(fn () => app(OwnershipBoundary::class)->query(ColumnAsset::query()->from('ownership_test_pivot_assets'), 'assets.column'))->toThrow(OwnershipViolation::class);
    });
});

it('retains captured operational ownership independently of a deleted optional business subject', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $operation = CapturedOperation::query()->findOrFail(1);
        $operation->forceFill(['subject_id' => 1])->save();
        ColumnAsset::query()->findOrFail(1)->delete();
        app(OwnershipBoundary::class)->assertRecord($operation, 'assets.captured');
        expect(CapturedOperation::query()->pluck('name')->all())->toBe(['private-7']);
    });
});

it('rejects a stale model after its native primary key was deleted and reused by another owner', function (): void {
    $stale = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): ColumnAsset {
        $record = ColumnAsset::query()->findOrFail(1);
        $copy = clone $record;
        $record->delete();

        return $copy;
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($stale): void {
        app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([...$assignment->attributes(), 'id' => 1, 'name' => 'new-private-8']));
        expect(fn () => app(OwnershipBoundary::class)->assertRecord($stale, 'assets.column'))->toThrow(OwnershipViolation::class);
    });
});

it('keeps native string owner 007 distinct from 7 without integer or UUID coercion', function (): void {
    $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
    {
        public function assertCanEnter(Authenticatable $actor, Model $owner): void
        {
            if ($actor->getAuthIdentifier() !== 1 || ! $owner instanceof StringCompany) {
                throw new OwnershipViolation;
            }
        }
    });
    foreach (['007', '7'] as $key) {
        app(OwnershipRunner::class)->run(StringCompany::query()->findOrFail($key), Actor::query()->firstOrFail(), function () use ($key): void {
            expect(StringAsset::query()->pluck('name')->all())->toBe(['private-'.$key]);
        });
    }
});
