<?php

declare(strict_types=1);

use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipWriter;

it('supplies the optional writer to standalone package actions without a runtime dependency', function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        foreach ([ColumnAsset::class => 'assets.column', PivotAsset::class => 'assets.pivot'] as $class => $resource) {
            $record = app(OwnershipWriter::class)->create($class, fn (array $attributes) => $class::query()->create([...$attributes, 'id' => 3, 'name' => 'package-created']));
            app(OwnershipBoundary::class)->assertRecord($record, $resource);
            expect($record::class)->toBe($class);
        }
    });
});
