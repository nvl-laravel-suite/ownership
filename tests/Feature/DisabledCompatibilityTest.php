<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

it('does not install schema or admit owner work when disabled', function (): void {
    config(['nvl-ownership.enabled' => false]);
    $this->allowOwners();
    expect(Schema::hasTable('nvl_ownership_resource_markers'))->toBeFalse()
        ->and(Schema::hasTable('nvl_tenancy_tenants'))->toBeFalse()
        ->and(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => true))
        ->toThrow(OwnershipViolation::class);
});

it('reads explicitly unowned resources without loading the retired tenancy runtime', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition(
        'fixture.global-companies', 'fixture', Company::class, OwnershipDefinition::unscoped(),
    ));
    $blocker = static function (string $class): void {
        if (str_starts_with($class, 'Nvl\\Support\\Tenancy\\')) {
            throw new LogicException('Native ownership loaded retired tenancy: '.$class);
        }
    };
    spl_autoload_register($blocker, true, true);
    try {
        $ids = app(OwnershipBoundary::class)->query(Company::query(), 'fixture.global-companies')->orderBy('id')->pluck('id')->all();
        expect($ids)->toBe([7, 8]);
    } finally {
        spl_autoload_unregister($blocker);
    }
});
