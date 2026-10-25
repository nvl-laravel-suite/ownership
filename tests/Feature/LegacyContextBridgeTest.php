<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Tenancy\Contracts\TenantRunner;

it('rejects two independently enabled enforcement runtimes', function (): void {
    config(['nvl-tenancy.enabled' => true]);
    $this->allowOwners();
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => true))
        ->toThrow(OwnershipConfigurationInvalid::class);
});

it('does not bind the retired tenant execution bridge', function (): void {
    expect($this->app->bound(TenantRunner::class))->toBeFalse();
});
