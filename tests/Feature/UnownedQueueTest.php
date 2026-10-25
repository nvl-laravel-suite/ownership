<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnershipRunner as RunnerContract;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Queue\UnownedProbeJob;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
});

it('runs explicitly unowned work with no actor owner or central authority in an owned deployment', function (): void {
    $queue = app(OwnershipQueueContext::class);
    expect(fn () => OwnershipJobEnvelope::capture(app(OwnershipContext::class)))->toThrow(OwnershipViolation::class);
    $envelope = $queue->captureUnowned();
    expect($envelope->context()->mode)->toBe(OwnershipContextMode::Unresolved)
        ->and($envelope->context()->principal)->toBeNull()->and($envelope->context()->owner)->toBeNull();
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($queue, $envelope): void {
        $queue->run($envelope, function (): void {
            expect(Actor::query()->count())->toBe(1)
                ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
            expect(fn () => ColumnAsset::query()->get())->toThrow(OwnershipViolation::class);
            expect(fn () => app(OwnershipContext::class)->requireOwner())->toThrow(OwnershipViolation::class);
        });
        expect(app(OwnershipContext::class)->requireOwner()->key)->toBe('8');
        expect(fn () => $queue->run($envelope, static fn () => throw new RuntimeException('global failure')))->toThrow(RuntimeException::class, 'global failure');
        expect(app(OwnershipContext::class)->requireOwner()->key)->toBe('8');
    });
});

it('requires the explicit unowned wire version and rejects authority promotion and stale generations', function (): void {
    $queue = app(OwnershipQueueContext::class);
    $wire = $queue->captureUnowned()->toArray();
    expect(fn () => OwnershipJobEnvelope::fromArray([...$wire, 'version' => 1]))->toThrow(OwnershipViolation::class);
    expect(fn () => OwnershipJobEnvelope::fromArray([...$wire, 'principal' => ['kind' => 'actor', 'type' => Actor::class, 'id' => '1']]))->toThrow(OwnershipViolation::class);
    $wire['generations'] = [];
    expect(fn () => $queue->run(OwnershipJobEnvelope::fromArray($wire), static fn () => 'unsafe'))->toThrow(OwnershipSchemaNotReady::class);
    config(['nvl-ownership.enabled' => false]);
    expect(fn () => $queue->captureUnowned())->toThrow(OwnershipViolation::class);
});

it('retains source transaction fencing when unowned work is dispatched from an admitted owner', function (): void {
    $queue = app(OwnershipQueueContext::class);
    $envelope = $queue->captureUnowned();
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($queue, $envelope): void {
        DB::transaction(function () use ($queue, $envelope): void {
            expect(fn () => $queue->run($envelope, static fn () => 'unsafe'))->toThrow(OwnershipViolation::class);
        });
        expect(ColumnAsset::query()->pluck('name')->all())->toBe(['private-7']);
    });
});

it('captures unowned native batch callbacks without allowing a batch to mix owner jobs', function (): void {
    QueueScenario::install();
    $queue = app(OwnershipQueueContext::class);
    $envelope = $queue->captureUnowned();
    $stored = $queue->run($envelope, function () use ($queue, $envelope) {
        $batch = $queue->captureBatch(Bus::batch([new UnownedProbeJob($envelope)])->onConnection('database'));
        expect($batch->options['nvl_ownership'])->toBe($envelope->toArray());

        return $batch->dispatch();
    });
    expect($stored->totalJobs)->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
    $owned = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => new UnownedProbeJob(OwnershipJobEnvelope::capture(app(OwnershipContext::class))));
    expect(fn () => $queue->captureBatch(Bus::batch([new UnownedProbeJob($envelope), $owned])))->toThrow(OwnershipViolation::class);
});

it('exposes explicit unowned execution through the injected runner contract and restores owner authority', function (): void {
    expect((new ReflectionClass(RunnerContract::class))->hasMethod('unowned'))->toBeTrue();
    $runner = app(RunnerContract::class);
    $runner->run(Company::findOrFail(8), Actor::firstOrFail(), function () use ($runner): void {
        $previous = app(OwnershipContext::class)->snapshot();
        expect(fn () => $runner->unowned(function (): never {
            expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved)
                ->and(app(OwnershipContext::class)->snapshot()->owner)->toBeNull()
                ->and(fn () => ColumnAsset::query()->count())->toThrow(OwnershipViolation::class);
            throw new RuntimeException('explicit global callback failure');
        }))->toThrow(RuntimeException::class, 'explicit global callback failure');
        expect(app(OwnershipContext::class)->snapshot())->toBe($previous);
    });
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});
