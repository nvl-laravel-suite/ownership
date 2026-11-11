<?php

declare(strict_types=1);

use Illuminate\Broadcasting\BroadcastEvent;
use Illuminate\Queue\CallQueuedClosure;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Queue\HostPlainJob;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Queue\UnownedProbeJob;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
    QueueScenario::install();
    config(['queue.default' => 'database']);
});

it('refuses uncaptured host, closure and broadcast work at dispatch in an owned deployment', function (Closure $job): void {
    expect(fn () => Queue::connection('database')->push($job()))->toThrow(OwnershipViolation::class, 'explicitly captured OwnershipJobEnvelope')
        ->and(DB::table('jobs')->count())->toBe(0);
})->with([
    'host job' => [static fn (): object => new HostPlainJob],
    'queued closure' => [static fn (): object => CallQueuedClosure::create(static fn (): null => null)],
    'broadcast event' => [static fn (): object => new BroadcastEvent(new stdClass)],
]);

it('stamps a declared uncaptured job with the unowned envelope and runs it without owner authority', function (bool $admitted): void {
    config(['nvl-ownership.queue.unowned_jobs' => [HostPlainJob::class]]);
    $dispatch = static function (): void {
        Bus::dispatch(new HostPlainJob('declared'));
    };
    $admitted ? app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), $dispatch) : $dispatch();

    $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);
    expect($payload['data']['nvl_ownership'])->toBe(app(OwnershipQueueContext::class)->captureUnowned()->toArray())
        ->and($payload['data']['nvl_ownership']['mode'])->toBe(OwnershipContextMode::Unresolved->value)
        ->and($payload['data']['nvl_ownership']['owner'])->toBeNull()
        ->and($payload['data']['nvl_ownership']['principal'])->toBeNull();

    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
        $previous = app(OwnershipContext::class)->snapshot();
        app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions(maxTries: 1));
        expect(app(OwnershipContext::class)->snapshot())->toBe($previous);
    });

    expect(DB::table('ownership_queue_probes')->get(['stage', 'owner', 'label'])->map(static fn (object $row): array => (array) $row)->all())
        ->toBe([['stage' => 'plain-refused', 'owner' => 'unresolved', 'label' => 'declared']])
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['from unowned code' => false, 'from an admitted owner' => true]);

it('runs a declared queued closure and each member of a declared chain as unowned work', function (): void {
    config(['nvl-ownership.queue.unowned_jobs' => [HostPlainJob::class, CallQueuedClosure::class]]);
    Bus::chain([new HostPlainJob('chain-first'), new HostPlainJob('chain-second')])->onConnection('database')->dispatch();
    Queue::connection('database')->push(CallQueuedClosure::create(static function (): void {
        DB::table('ownership_queue_probes')->insert(['stage' => 'closure', 'owner' => app(OwnershipContext::class)->snapshot()->mode->value, 'label' => 'closure']);
    }));

    while (($job = Queue::connection('database')->pop()) !== null) {
        app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
    }

    expect(DB::table('ownership_queue_probes')->orderBy('label')->pluck('owner', 'label')->all())
        ->toBe(['chain-first' => 'unresolved', 'chain-second' => 'unresolved', 'closure' => 'unresolved'])
        ->and(DB::table('ownership_queue_probes')->where('stage', 'plain-exposed')->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->count())->toBe(0);

    config(['nvl-ownership.queue.unowned_jobs' => [HostPlainJob::class]]);
    Bus::chain([new HostPlainJob('mixed-head'), CallQueuedClosure::create(static fn (): null => null)])->onConnection('database')->dispatch();
    app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions(maxTries: 1));
    expect(DB::table('ownership_queue_probes')->where('label', 'mixed-head')->exists())->toBeFalse()
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('Chained owner jobs require the captured envelope contract.');
});

it('keeps every undeclared class, declared owned models and explicit capture under their existing rules', function (): void {
    config(['nvl-ownership.queue.unowned_jobs' => [HostPlainJob::class]]);

    expect(fn () => Queue::connection('database')->push(CallQueuedClosure::create(static fn (): null => null)))->toThrow(OwnershipViolation::class)
        ->and(fn () => Queue::connection('database')->push(new HostPlainJob('owned-model', ColumnAsset::withoutGlobalScopes()->findOrFail(1))))->toThrow(OwnershipViolation::class)
        ->and(DB::table('jobs')->count())->toBe(0);

    config(['nvl-ownership.queue.unowned_jobs' => [HostPlainJob::class, UnownedProbeJob::class]]);
    $owned = app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), function (): array {
        Queue::connection('database')->push(new UnownedProbeJob(OwnershipJobEnvelope::capture(app(OwnershipContext::class))));

        return json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR)['data']['nvl_ownership'];
    });
    expect($owned['mode'])->toBe(OwnershipContextMode::Owner->value)
        ->and($owned['owner']['key'])->toBe('7');
});

it('quarantines declared work on a worker that no longer declares its class or whose payload claims authority', function (string $tamper): void {
    config(['nvl-ownership.queue.unowned_jobs' => [HostPlainJob::class]]);
    Queue::connection('database')->push(new HostPlainJob('stale'));
    if ($tamper === 'undeclared') {
        config(['nvl-ownership.queue.unowned_jobs' => []]);
    } else {
        $owner = app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), static fn (): array => OwnershipJobEnvelope::capture(app(OwnershipContext::class))->toArray());
        $payload = json_decode(DB::table('jobs')->value('payload'), true, flags: JSON_THROW_ON_ERROR);
        $payload['data']['nvl_ownership'] = $owner;
        DB::table('jobs')->update(['payload' => json_encode($payload, JSON_THROW_ON_ERROR)]);
    }

    app('queue.worker')->process('database', Queue::connection('database')->pop(), new WorkerOptions(maxTries: 1));

    expect(DB::table('ownership_queue_probes')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('NVL queue envelope rejected:');
})->with(['undeclared', 'owner authority']);

it('rejects a malformed declaration list instead of treating it as empty', function (mixed $declared): void {
    config(['nvl-ownership.queue.unowned_jobs' => $declared]);

    expect(fn () => Queue::connection('database')->push(new HostPlainJob))->toThrow(OwnershipConfigurationInvalid::class)
        ->and(DB::table('jobs')->count())->toBe(0);
})->with(['scalar' => [HostPlainJob::class], 'keyed map' => [[HostPlainJob::class => true]], 'non-string entry' => [[HostPlainJob::class, 7]]]);
