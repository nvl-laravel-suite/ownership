<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

beforeEach(function (): void {
    config(['nvl-ownership.system_operations' => ['media.generate'], 'nvl-ownership.central_operations' => ['catalog.review']]);
    $this->app->bind(OwnerSystemAccess::class, static fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void {}
    });
    $this->app->bind(CentralAccess::class, static fn () => new class implements CentralAccess
    {
        public function assertCanRun(string $operation, Authenticatable $actor): void {}
    });
});

it('requires the durable audit schema before admitting a privileged callback', function (): void {
    $reference = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
    $called = false;
    expect(fn () => app(OwnershipRunner::class)->runSystem($reference, 'media.generate', function () use (&$called): void {
        $called = true;
    }))
        ->toThrow(OwnershipSchemaNotReady::class);
    expect($called)->toBeFalse();
});

it('refuses a missing audit table after the native store was installed', function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    DB::getSchemaBuilder()->drop('nvl_ownership_operation_facts');
    $reference = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
    expect(fn () => app(OwnershipRunner::class)->runSystem($reference, 'media.generate', fn () => 'leak'))->toThrow(OwnershipSchemaNotReady::class);
});

it('records authorized system and central facts before work and preserves them after failure', function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    $reference = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
    expect(fn () => app(OwnershipRunner::class)->runSystem($reference, 'media.generate', function (): void {
        expect(DB::table('nvl_ownership_operation_facts')->count())->toBe(1)
            ->and(app(OwnershipContext::class)->requireOwner()->key)->toBe('7');
        throw new RuntimeException('The privileged callback failed.');
    }))->toThrow(RuntimeException::class);
    expect(app(OwnershipRunner::class)->central('catalog.review', Actor::query()->firstOrFail(), function (): string {
        expect(DB::table('nvl_ownership_operation_facts')->count())->toBe(2)
            ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Central);

        return 'reviewed';
    }))->toBe('reviewed')
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('denies nonallowlisted operations and caller-owned audit transactions', function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    $reference = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
    expect(fn () => app(OwnershipRunner::class)->runSystem($reference, 'unlisted', fn () => true))->toThrow(OwnershipViolation::class);
    DB::beginTransaction();
    try {
        expect(fn () => app(OwnershipRunner::class)->runSystem($reference, 'media.generate', fn () => true))->toThrow(OwnershipViolation::class)
            ->and(DB::transactionLevel())->toBe(1)
            ->and(DB::table('nvl_ownership_operation_facts')->count())->toBe(0);
    } finally {
        DB::rollBack();
    }
});
