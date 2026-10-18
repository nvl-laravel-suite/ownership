<?php

declare(strict_types=1);

use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeJob;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
    ProbeJob::$observations = [];
});

/** Capture Laravel's real serialized bytes while the producer is admitted. */
function ownershipProbePayload(?ColumnAsset $record = null): string
{
    return app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn (): string => (new ReflectionMethod(Queue::connection('sync'), 'createPayload'))->invoke(Queue::connection('sync'), new ProbeJob(record: $record), 'default'));
}

it('enters captured ownership before native restoration and restores the surrounding owner', function (): void {
    $record = ColumnAsset::withoutGlobalScopes()->findOrFail(1);
    $payload = ownershipProbePayload($record);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($payload): void {
        (new SyncJob(app(), $payload, 'sync', 'default'))->fire();
        expect(ColumnAsset::query()->pluck('name')->all())->toBe(['private-8']);
    });
    expect(array_column(ProbeJob::$observations, 'owner'))->toBe(['7', '7']);
});

it('guards direct terminal failure restoration even when handling never began', function (): void {
    (new SyncJob(app(), ownershipProbePayload(), 'sync', 'default'))->fail(new RuntimeException('exhausted'));
    expect(array_column(ProbeJob::$observations, 'stage'))->toBe(['restore', 'failed'])
        ->and(array_column(ProbeJob::$observations, 'owner'))->toBe(['7', '7']);
});

it('denies missing and mismatched metadata before both native entry points', function (bool $failure): void {
    $payload = json_decode(ownershipProbePayload(), true, flags: JSON_THROW_ON_ERROR);
    unset($payload['data']['nvl_ownership']);
    $job = new SyncJob(app(), json_encode($payload, JSON_THROW_ON_ERROR), 'sync', 'default');
    expect(fn () => $failure ? $job->fail(new RuntimeException) : $job->fire())->toThrow(OwnershipViolation::class)
        ->and(ProbeJob::$observations)->toBe([]);
})->with([false, true]);

it('rejects a foreign captured model before any command restoration', function (): void {
    $foreign = ColumnAsset::withoutGlobalScopes()->findOrFail(2);
    expect(fn () => ownershipProbePayload($foreign))->toThrow(OwnershipViolation::class)
        ->and(ProbeJob::$observations)->toBe([]);
});

it('preserves explicit capture during direct synchronous native bus execution', function (): void {
    $command = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), static fn (): ProbeJob => new ProbeJob);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($command): void {
        app(Dispatcher::class)->dispatchNow($command);
        expect(ColumnAsset::query()->pluck('name')->all())->toBe(['private-8']);
    });
    expect(array_column(ProbeJob::$observations, 'owner'))->toBe(['7']);
});

it('preserves semantic envelope equality when a native transport reorders JSON object keys', function (): void {
    $wire = json_decode(ownershipProbePayload(), true, flags: JSON_THROW_ON_ERROR);
    $wire['data']['nvl_ownership'] = array_reverse($wire['data']['nvl_ownership'], true);
    $wire['data']['nvl_ownership']['owner'] = array_reverse($wire['data']['nvl_ownership']['owner'], true);
    $wire['data']['nvl_ownership']['principal'] = array_reverse($wire['data']['nvl_ownership']['principal'], true);
    (new SyncJob(app(), json_encode($wire, JSON_THROW_ON_ERROR), 'sync', 'default'))->fire();
    expect(array_column(ProbeJob::$observations, 'owner'))->toBe(['7', '7']);
});
