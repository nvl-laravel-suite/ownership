<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

it('does not install schema or admit owner work when disabled', function (): void {
    config(['nvl-ownership.enabled' => false]);
    $this->allowOwners();
    expect(Schema::hasTable('nvl_ownership_resource_markers'))->toBeFalse()
        ->and(Schema::hasTable('nvl_tenancy_tenants'))->toBeFalse()
        ->and(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => true))
        ->toThrow(OwnershipViolation::class);
});
