<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipAdoptionCoordinator;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipVerifiedAdmissions;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
    $this->statements = static function (Closure $work): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            $work();

            return count(DB::getQueryLog());
        } finally {
            DB::disableQueryLog();
        }
    };
    $this->asOwner = static fn (int $company, Closure $work): mixed => app(OwnershipRunner::class)
        ->run(Company::query()->findOrFail($company), Actor::query()->firstOrFail(), $work);
});

it('verifies a resource once per admitted context and then runs only the owned query', function (): void {
    ($this->asOwner)(7, function (): void {
        $first = ($this->statements)(static fn () => ColumnAsset::query()->get());
        $second = ($this->statements)(static fn () => ColumnAsset::query()->get());
        $count = ($this->statements)(static fn () => ColumnAsset::query()->count());
        $otherResource = ($this->statements)(static fn () => PivotAsset::query()->get());

        expect($first)->toBeGreaterThan(1)
            ->and($second)->toBe(1)
            ->and($count)->toBe(1)
            ->and($otherResource)->toBeGreaterThan(1)
            ->and($otherResource)->toBeLessThan($first)
            ->and(ColumnAsset::query()->pluck('id')->all())->toBe([1]);
    });
});

it('verifies again in every new owner context', function (): void {
    ($this->asOwner)(7, static fn () => ColumnAsset::query()->get());

    ($this->asOwner)(8, function (): void {
        expect(($this->statements)(static fn () => ColumnAsset::query()->get()))->toBeGreaterThan(1)
            ->and(ColumnAsset::query()->pluck('id')->all())->toBe([2]);
    });
});

it('verifies again after a coordinated adoption mutation', function (): void {
    $connection = (new ColumnAsset)->getConnection();
    $snapshot = ($this->asOwner)(7, static function (): OwnershipSnapshot {
        ColumnAsset::query()->get();

        return app(OwnershipContext::class)->snapshot();
    });
    $verified = app(OwnershipVerifiedAdmissions::class);
    expect($verified->has($snapshot, 'assets.column', $connection))->toBeTrue();

    $coordinator = app(OwnershipAdoptionCoordinator::class);
    $coordinator->refreshConstraints(
        $coordinator->resume((string) DB::table('nvl_ownership_adoption_runs')->value('id')),
        new OwnershipOperation('ownership.constraints.refresh', 'fixture', '1'),
    );

    expect($verified->has($snapshot, 'assets.column', $connection))->toBeFalse();
});

it('remembers a verification only for the exact snapshot, resource and connection object', function (): void {
    $verified = new OwnershipVerifiedAdmissions;
    $snapshot = new OwnershipSnapshot(OwnershipContextMode::Unresolved);
    $connection = DB::connection();
    $reconnected = clone $connection;

    $verified->remember($snapshot, 'assets.column', $connection);

    expect($verified->has($snapshot, 'assets.column', $connection))->toBeTrue()
        ->and($verified->has($snapshot, 'assets.pivot', $connection))->toBeFalse()
        ->and($verified->has(new OwnershipSnapshot(OwnershipContextMode::Unresolved), 'assets.column', $connection))->toBeFalse()
        ->and($verified->has($snapshot, 'assets.column', $reconnected))->toBeFalse();

    $verified->rememberOwner($snapshot, $connection, 'App\\Models\\Company');

    expect($verified->owner($snapshot, $connection))->toBe('App\\Models\\Company')
        ->and($verified->owner($snapshot, $reconnected))->toBeNull()
        ->and($verified->owner(new OwnershipSnapshot(OwnershipContextMode::Unresolved), $connection))->toBeNull();

    $verified->forget();

    expect($verified->has($snapshot, 'assets.column', $connection))->toBeFalse()
        ->and($verified->owner($snapshot, $connection))->toBeNull();
});
