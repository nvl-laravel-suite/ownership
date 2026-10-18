<?php

declare(strict_types=1);

use Nvl\Data\Providers\DataServiceProvider;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Translations\Providers\TranslationsServiceProvider;

it('denies entry when a loaded stateful integration has not converted its resource graph', function (): void {
    $this->allowOwners();
    $this->app->register(DataServiceProvider::class);
    $this->app->register(TranslationsServiceProvider::class);
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => 'leak'))
        ->toThrow(OwnershipConfigurationInvalid::class);
});
