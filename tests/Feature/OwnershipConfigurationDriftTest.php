<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\MaintenanceMode;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipConfigurationFingerprint;
use Nvl\Ownership\Tests\Fixtures\Adoption\Record;
use Nvl\Ownership\Tests\Fixtures\Adoption\RecordsAdapter;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

beforeEach(function (): void {
    (require __DIR__.'/../../database/migrations/ownership/2026_10_18_000001_nvl_ownership_create_adoption_tables.php')->up();
    DB::getSchemaBuilder()->create('ownership_test_records', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id')->nullable();
    });
    DB::table('ownership_test_records')->insert(['id' => 1]);
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.records', 'fixture', Record::class, OwnershipDefinition::column(Company::class, 'company_id')));
    app(OwnershipAdoptionRegistry::class)->register('fixture', RecordsAdapter::class);
    $this->app->bind(OwnershipAdoptionAccess::class, static fn () => new class implements OwnershipAdoptionAccess
    {
        public function authorize(OwnershipOperation $operation): void {}
    });
    $this->app->instance(MaintenanceMode::class, new class implements MaintenanceMode
    {
        public function activate(array $payload): void {}

        public function deactivate(): void {}

        public function active(): bool
        {
            return true;
        }

        public function data(): array
        {
            return [];
        }
    });
    $this->operation = new OwnershipOperation('adopt', 'operator', '1');
    $this->mapping = new OwnershipMapping('fixture.records', '1', app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7)));
});

it('rejects resource morph identity drift before resuming package callbacks', function (): void {
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $plan = $coordinator->prepare(['fixture'], [$this->mapping], $this->operation);
    $previous = Relation::morphMap();
    try {
        Relation::morphMap(['changed-resource' => Record::class], false);
        expect(fn () => $coordinator->resume($plan->id))->toThrow(OwnershipConfigurationInvalid::class);
    } finally {
        Relation::morphMap($previous, false);
    }
});

it('reads each store once per fingerprint operation without retaining its identity across calls', function (bool $graph): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('fixture.other', 'fixture', ColumnAsset::class, ColumnAsset::ownershipDefinition()));
    $fingerprints = app(OwnershipConfigurationFingerprint::class);
    $read = $graph
        ? fn (): string => $fingerprints->hash(['fixture.records', 'fixture.other'])
        : fn (): string => $fingerprints->fingerprint('fixture.records');
    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $before = $read();
        expect($connection->getQueryLog())->toHaveCount(2);

        $connection->table(OwnershipStorageTables::Stores)->where('id', 1)->update(['store_id' => '550e8400-e29b-41d4-a716-446655440000']);
        $connection->flushQueryLog();
        expect($read())->not->toBe($before)
            ->and($connection->getQueryLog())->toHaveCount(2);
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
})->with(['single resource' => false, 'resource graph' => true]);

it('reads the owner store once per identity operation without retaining it across calls', function (): void {
    $resolver = app(OwnerIdentityResolver::class);
    $company = Company::query()->findOrFail(7);
    $connection = DB::connection();
    $connection->enableQueryLog();
    try {
        $before = $resolver->reference($company);
        expect($connection->getQueryLog())->toHaveCount(2);
        $connection->flushQueryLog();
        expect($resolver->resolve($before)->is($company))->toBeTrue()
            ->and($connection->getQueryLog())->toHaveCount(3);

        $connection->table(OwnershipStorageTables::Stores)->where('id', 1)->update(['store_id' => '550e8400-e29b-41d4-a716-446655440000']);
        $connection->flushQueryLog();
        expect($resolver->reference($company)->storage)->toBe('550e8400-e29b-41d4-a716-446655440000.ownership_test_companies')
            ->and($connection->getQueryLog())->toHaveCount(2)
            ->and(fn () => $resolver->resolve($before))->toThrow(OwnershipViolation::class);
    } finally {
        $connection->disableQueryLog();
        $connection->flushQueryLog();
    }
});

it('rejects a replacement declaration after immutable preparation', function (): void {
    $plan = app(OwnershipAdoptionCoordinator::class)->prepare(['fixture'], [$this->mapping], $this->operation);
    $reconfigured = new OwnershipResourceRegistry;
    $reconfigured->register(new OwnershipResourceDefinition('fixture.records', 'fixture', Record::class, OwnershipDefinition::pivot(Company::class, 'company_records', 'record_id', 'company_id')));
    $this->app->instance(OwnershipResourceRegistry::class, $reconfigured);
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->resume($plan->id))->toThrow(OwnershipConfigurationInvalid::class);
});

it('reloads owner availability and rejects a deleted mapped owner on resume', function (): void {
    $plan = app(OwnershipAdoptionCoordinator::class)->prepare(['fixture'], [$this->mapping], $this->operation);
    Company::query()->whereKey(7)->delete();
    expect(fn () => app(OwnershipAdoptionCoordinator::class)->resume($plan->id))->toThrow(OwnershipViolation::class);
});

it('resumes an interrupted prepare using the original generation and durable input', function (): void {
    config(['ownership_test.callback' => static function (): void {
        throw new RuntimeException('Interrupted preparation.');
    }]);
    $coordinator = app(OwnershipAdoptionCoordinator::class);
    expect(fn () => $coordinator->prepare(['fixture'], [$this->mapping], $this->operation))->toThrow(RuntimeException::class);
    $runId = DB::table('nvl_ownership_adoption_runs')->value('id');
    config(['ownership_test.callback' => null]);
    $plan = $coordinator->resume($runId);
    expect($coordinator->backfill($plan, 100, $this->operation))->toBeTrue();
    $coordinator->activate($plan, $this->operation);
    expect(DB::table('nvl_ownership_adoption_runs')->where('id', $runId)->value('status'))->toBe('active');
});
