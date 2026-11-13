<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PolymorphicAsset;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OptsIntoAdoptedSchemaReuse::class);

beforeEach(function (): void {
    $this->allowOwners();
    $this->installResourceScenario();
});

it('resolves allowlisted polymorphic parents without coercing their native string identifiers', function (): void {
    foreach ([7, 8] as $company) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($company), Actor::query()->firstOrFail(), function () use ($company): void {
            expect(PolymorphicAsset::query()->pluck('name')->all())->toBe(['private-'.$company]);
            app(OwnershipBoundary::class)->assertRecord(PolymorphicAsset::query()->firstOrFail(), 'assets.poly');
        });
    }
});

it('denies unknown morph types and dirty parent reclassification independently of loaded relations', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $child = PolymorphicAsset::query()->firstOrFail();
        $child->setAttribute('subject_type', ColumnAsset::class);
        expect(fn () => app(OwnershipBoundary::class)->assertRecord($child, 'assets.poly'))->toThrow(OwnershipViolation::class);
        DB::table('ownership_test_polymorphic_assets')->insert(['id' => 3, 'subject_type' => 'unknown-parent', 'subject_id' => '007', 'name' => 'unknown', 'owner_scope_key' => $child->getRawOriginal('owner_scope_key')]);
        expect(PolymorphicAsset::query()->pluck('id')->all())->toBe([1]);
        $unknown = PolymorphicAsset::withoutGlobalScopes()->findOrFail(3);
        $unknown->setRelation('subject', ColumnAsset::withoutGlobalScopes()->findOrFail(1));
        expect(fn () => app(OwnershipBoundary::class)->assertRecord($unknown, 'assets.poly'))->toThrow(OwnershipViolation::class);
    });
});
