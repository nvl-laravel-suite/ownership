<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Resources\ConfiguredStringCompany;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Ownership\Tests\Fixtures\Resources\StringAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\StringCompany;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

it('resolves declared base owners through the existing configured model registry', function (): void {
    $this->allowOwners(['007']);
    app(OwnerRegistry::class)->registerReplacement('fixture.string-company', StringCompany::class, ConfiguredStringCompany::class);
    config()->set('ownership-test.string-owner', ConfiguredStringCompany::class);
    ResourceScenario::install($this->app);
    app(OwnershipRunner::class)->run(ConfiguredStringCompany::query()->findOrFail('007'), Actor::query()->firstOrFail(), function (): void {
        expect(StringAsset::query()->pluck('name')->all())->toBe(['private-007']);
    });
});

it('rejects lossy owner foreign key storage before any adoption backfill', function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql') {
        $this->markTestSkipped('The native engine rejects this incompatible FK at host DDL creation.');
    }
    config()->set('ownership-test.lossy-column', true);
    expect(fn () => ResourceScenario::install($this->app))->toThrow(OwnershipSchemaNotReady::class);
});
