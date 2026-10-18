<?php

declare(strict_types=1);

use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnershipRunner as RunnerContract;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

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
