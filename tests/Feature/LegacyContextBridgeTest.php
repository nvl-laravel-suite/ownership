<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

it('rejects two independently enabled enforcement runtimes', function (): void {
    config(['nvl-tenancy.enabled' => true]);
    $this->allowOwners();
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => true))
        ->toThrow(OwnershipConfigurationInvalid::class);
});

it('denies legacy tenant entry without an admitted explicit owner mapping', function (): void {
    expect(fn () => app(TenantRunner::class)->run(new TenantId('10000000-0000-4000-8000-000000000007'), fn () => 'leak'))
        ->toThrow(OwnershipViolation::class);
});
