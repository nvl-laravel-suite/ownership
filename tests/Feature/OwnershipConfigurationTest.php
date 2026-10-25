<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ChildAsset;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

it('denies entry when a declared native ownership resource graph is incomplete', function (): void {
    $this->allowOwners();
    $this->app->make(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition(
        'assets.child', 'assets', ChildAsset::class, ChildAsset::ownershipDefinition(), parentResource: 'assets.missing',
    ));
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => 'leak'))
        ->toThrow(OwnershipConfigurationInvalid::class);
});
