<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnershipRunner as RunnerContract;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Ownership\Tests\Fixtures\Resources\StringCompany;
use Nvl\Ownership\Tests\Fixtures\StatefulOwnershipParticipant;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextParticipants;

it('restores nested owner context after callbacks throw', function (): void {
    $this->allowOwners();
    $runner = app(OwnershipRunner::class);
    $actor = Actor::query()->firstOrFail();
    $runner->run(Company::query()->findOrFail(7), $actor, function () use ($runner, $actor): void {
        expect(fn () => $runner->run(Company::query()->findOrFail(8), $actor, static function (): never {
            throw new RuntimeException('callback failed');
        }))->toThrow(RuntimeException::class, 'callback failed');
        expect(app(OwnershipContext::class)->requireOwner()->key)->toBe('7');
    });
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('binds the supported ownership workflow interface to the native implementation', function (): void {
    expect(app(RunnerContract::class))->toBeInstanceOf(OwnershipRunner::class);
});

it('uses the new scope when a runner is retained across request lifecycle cleanup', function (): void {
    $this->allowOwners();
    $runner = app(OwnershipRunner::class);
    $old = app(OwnershipContext::class);
    $this->app->forgetScopedInstances();
    $runner->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($old): void {
        expect(app(OwnershipContext::class))->not->toBe($old)
            ->and(app(OwnershipContext::class)->requireOwner()->key)->toBe('8')
            ->and($old->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
    });
});

it('denies implicit system and central operations', function (): void {
    $this->allowOwners();
    $runner = app(OwnershipRunner::class);
    $reference = $runner->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => app(OwnershipContext::class)->requireOwner());
    expect(fn () => $runner->runSystem($reference, 'media.scan', fn () => 'leak'))->toThrow(OwnershipViolation::class)
        ->and(fn () => $runner->central('all.resources', Actor::query()->firstOrFail(), fn () => 'leak'))->toThrow(OwnershipViolation::class);
});

it('retains the named central policy argument contract when denying by default', function (): void {
    expect(fn () => app(CentralAccess::class)->assertCanRun(operation: 'all.resources', actor: Actor::query()->firstOrFail()))
        ->toThrow(OwnershipViolation::class);
});

it('rejects an owner change inside a participating transaction', function (): void {
    $this->allowOwners();
    $runner = app(OwnershipRunner::class);
    $runner->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($runner): void {
        DB::beginTransaction();
        try {
            expect(fn () => $runner->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), static fn () => 'unsafe'))->toThrow(OwnershipViolation::class);
            expect(app(OwnershipContext::class)->requireOwner()->key)->toBe('7')
                ->and(DB::transactionLevel())->toBe(1);
        } finally {
            DB::rollBack();
        }
    });
});

it('rejects distinct numeric string owners inside a participating transaction', function (): void {
    $this->allowOwners(['007', '7']);
    ResourceScenario::install($this->app);
    $runner = app(OwnershipRunner::class);
    $actor = Actor::query()->firstOrFail();

    $runner->run(StringCompany::query()->findOrFail('007'), $actor, function () use ($runner, $actor): void {
        DB::beginTransaction();
        try {
            expect(fn () => $runner->run(StringCompany::query()->findOrFail('7'), $actor, static fn () => 'unsafe'))
                ->toThrow(OwnershipViolation::class, 'Ownership context cannot change inside a participating transaction.');
            expect(app(OwnershipContext::class)->requireOwner()->key)->toBe('007')
                ->and(DB::transactionLevel())->toBe(1);
        } finally {
            DB::rollBack();
        }
    });
});

it('allows equivalent distinct owner references inside a participating transaction', function (): void {
    $this->allowOwners(['007']);
    ResourceScenario::install($this->app);
    $runner = app(OwnershipRunner::class);
    $actor = Actor::query()->firstOrFail();

    $runner->run(StringCompany::query()->findOrFail('007'), $actor, function () use ($runner, $actor): void {
        $previous = app(OwnershipContext::class)->requireOwner();
        DB::beginTransaction();
        try {
            $runner->run(StringCompany::query()->findOrFail('007'), $actor, function () use ($previous): void {
                $current = app(OwnershipContext::class)->requireOwner();
                expect($current)->not->toBe($previous)
                    ->and($current->toArray())->toBe($previous->toArray())
                    ->and(DB::transactionLevel())->toBe(1);
            });
            expect(app(OwnershipContext::class)->requireOwner())->toBe($previous)
                ->and(DB::transactionLevel())->toBe(1);
        } finally {
            DB::rollBack();
        }
    });
});

it('rolls back leaked source transactions and revokes the corrupted execution scope', function (): void {
    $this->allowOwners();
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), static function (): void {
        DB::beginTransaction();
        Company::query()->create(['id' => 99]);
    }))->toThrow(OwnershipViolation::class);
    expect(DB::transactionLevel())->toBe(0)
        ->and(Company::query()->whereKey(99)->exists())->toBeFalse()
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('restores retained integration state after nested owner callbacks fail', function (): void {
    $this->allowOwners();
    $participant = new StatefulOwnershipParticipant;
    $this->app->instance(StatefulOwnershipParticipant::class, $participant);
    app(OwnershipContextParticipants::class)->register(StatefulOwnershipParticipant::class);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($participant): void {
        expect($participant->value)->toBe('7');
        expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($participant): void {
            expect($participant->value)->toBe('8');
            throw new RuntimeException('Nested callback failed.');
        }))->toThrow(RuntimeException::class);
        expect($participant->value)->toBe('7');
    });
    expect($participant->value)->toBe('original');
});
