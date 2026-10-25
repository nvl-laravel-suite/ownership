<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipConnections;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\UnrepresentableOwner;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

it('distinguishes an absent optional store from malformed or missing installed identities', function (): void {
    $connection = DB::connection();
    $connections = app(OwnershipConnections::class);
    expect($connections->optionalIdentity($connection))->toBeNull()
        ->and(fn (): string => $connections->identity($connection))->toThrow(OwnershipSchemaNotReady::class);

    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    $connection->enableQueryLog();
    $connection->flushQueryLog();
    $identity = $connections->optionalIdentity($connection);
    $queries = array_column($connection->getQueryLog(), 'query');
    $connection->disableQueryLog();
    expect($identity)->toBeString()
        ->and(count(array_filter($queries, static fn (string $sql): bool => str_contains($sql, 'sqlite_master') && str_contains($sql, 'nvl_ownership_stores'))))->toBe(1);

    DB::table('nvl_ownership_stores')->where('id', 1)->update(['store_id' => 'malformed']);
    expect(fn () => $connections->optionalIdentity($connection))->toThrow(OwnershipSchemaNotReady::class);
    DB::table('nvl_ownership_stores')->where('id', 1)->delete();
    expect(fn () => $connections->optionalIdentity($connection))->toThrow(OwnershipSchemaNotReady::class);
});

it('denies actor admission without a host access policy', function (): void {
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => 'leak'))
        ->toThrow(OwnershipViolation::class);
});

it('treats implicit and explicitly hydrated default connections as the same canonical storage', function (): void {
    $owner = Company::query()->findOrFail(7);
    $implicit = clone $owner;
    $implicit->setConnection(null);
    expect(app(OwnerIdentityResolver::class)->reference($owner))
        ->toEqual(app(OwnerIdentityResolver::class)->reference($implicit));
});

it('preserves native integer key bytes without overflowing PHP integers', function (): void {
    $owner = Company::query()->findOrFail(7);
    $owner->setRawAttributes(['id' => '18446744073709551615', 'active' => true], true);
    expect(app(OwnerIdentityResolver::class)->reference($owner)->key)->toBe('18446744073709551615');
});

it('denies noncanonical persisted integer keys rather than casting another owner identity', function (): void {
    $owner = Company::query()->findOrFail(7);
    $owner->setRawAttributes(['id' => '007', 'active' => true], true);
    expect(fn () => app(OwnerIdentityResolver::class)->reference($owner))->toThrow(OwnershipViolation::class);
});

it('rejects an owner whose model key would authorize a different persisted identity', function (): void {
    Schema::create('ownership_test_unrepresentable_owners', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->boolean('active');
    });
    DB::table('ownership_test_unrepresentable_owners')->insert(['id' => '18446744073709551615', 'active' => true]);
    app(OwnerRegistry::class)->reference(UnrepresentableOwner::class, 'fixture.unrepresentable');
    $this->allowOwners([PHP_INT_MAX]);
    expect(fn () => app(OwnershipRunner::class)->run(UnrepresentableOwner::query()->firstOrFail(), Actor::query()->firstOrFail(), fn () => 'leak'))
        ->toThrow(OwnershipViolation::class);
});

it('admits native integer owners without tenancy or Auth and restores unresolved context', function (): void {
    $this->allowOwners();
    $result = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): string {
        expect(app(OwnershipContext::class)->requireOwner()->key)->toBe('7')
            ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Owner);

        return 'accepted';
    });
    expect($result)->toBe('accepted')
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved)
        ->and($this->app->providerIsLoaded('Nvl\Auth\Providers\AuthServiceProvider'))->toBeFalse()
        ->and($this->app->providerIsLoaded('Nvl\Tenancy\Providers\TenancyServiceProvider'))->toBeFalse();
});

it('reloads owner availability and rejects a deleted owner or forged table', function (): void {
    $this->allowOwners();
    $owner = Company::query()->findOrFail(7);
    Company::query()->whereKey(7)->update(['active' => false]);
    expect(fn () => app(OwnershipRunner::class)->run($owner, Actor::query()->firstOrFail(), fn () => true))->toThrow(OwnershipViolation::class);
    Company::query()->whereKey(7)->delete();
    expect(fn () => app(OwnershipRunner::class)->run($owner, Actor::query()->firstOrFail(), fn () => true))->toThrow(OwnershipViolation::class);
    $owner = Company::query()->findOrFail(8);
    $owner->setTable('ownership_test_actors');
    expect(fn () => app(OwnershipRunner::class)->run($owner, Actor::query()->firstOrFail(), fn () => true))->toThrow(OwnershipViolation::class);
});
