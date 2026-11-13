<?php

declare(strict_types=1);

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeJob;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Queue\UniqueJob;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

uses(OptsIntoAdoptedSchemaReuse::class);

beforeEach(function (): void {
    $this->allowOwners();
    $this->installColumnScenario(queue: true);
    ProbeJob::$observations = [];
});

it('runs native persisted batch callbacks in the captured owner scope', function (): void {
    $batch = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): Batch {
        $batch = Bus::batch([new ProbeJob])->onConnection('database')->then(static function (Batch $batch): void {
            Cache::put('ownership-batch-callback', app(OwnershipContext::class)->requireOwner()->key);
        });

        return app(OwnershipQueueContext::class)->captureBatch($batch)->dispatch();
    });
    $queue = Queue::connection('database');
    app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
    expect(Cache::get('ownership-batch-callback'))->toBe('7')
        ->and(array_column(ProbeJob::$observations, 'owner'))->toBe(['7', '7']);
});

it('rejects mixed captured owners before any batch or jobs are stored', function (): void {
    $foreign = app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), static fn (): ProbeJob => new ProbeJob);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($foreign): void {
        expect(fn () => app(OwnershipQueueContext::class)->captureBatch(Bus::batch([new ProbeJob, $foreign])))->toThrow(OwnershipViolation::class);
    });
    expect(DB::table('job_batches')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0);
});

it('rejects hydrated models nested in signed native batch callbacks before persistence', function (): void {
    $foreign = ColumnAsset::withoutGlobalScopes()->findOrFail(2);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($foreign): void {
        $batch = Bus::batch([new ProbeJob])->onConnection('database')->then(static function (Batch $batch) use ($foreign): void {
            Cache::put('unsafe-foreign-model', $foreign->getKey());
        });
        expect(fn () => app(OwnershipQueueContext::class)->captureBatch($batch)->dispatch())->toThrow(OwnershipViolation::class);
    });
    expect(DB::table('job_batches')->count())->toBe(0)->and(DB::table('jobs')->count())->toBe(0)
        ->and(Cache::has('unsafe-foreign-model'))->toBeFalse();
});

it('retains producer capture when afterResponse scheduling executes under another owner', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), static fn () => app(Dispatcher::class)->dispatchAfterResponse(new ProbeJob));
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), static fn () => app()->terminate());
    expect(array_column(ProbeJob::$observations, 'owner'))->toBe(['7']);
});

it('acquires separate native uniqueness locks from producer capture before publication', function (): void {
    config()->set('queue.default', 'database');
    $job = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), static fn (): UniqueJob => new UniqueJob);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($job): void {
        $first = new PendingDispatch($job);
        unset($first);
        UniqueJob::dispatch();
        UniqueJob::dispatch();
    });
    expect(DB::table('jobs')->count())->toBe(2);
    $owners = [];
    foreach (DB::table('jobs')->pluck('payload') as $payload) {
        $wire = json_decode($payload, true, flags: JSON_THROW_ON_ERROR);
        $owners[] = $wire['data']['nvl_ownership']['owner']['key'];
    }
    sort($owners);
    expect($owners)->toBe(['7', '8']);
});

it('preserves native signed callback verification after inert ownership inspection', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $batch = app(OwnershipQueueContext::class)->captureBatch(Bus::batch([new ProbeJob])->onConnection('database')->then(QueueScenario::batchCallback()))->dispatch();
        $serialized = DB::table('job_batches')->where('id', $batch->id)->value('options');
        $postgres = DB::connection()->getDriverName() === 'pgsql';
        if ($postgres) {
            $serialized = base64_decode($serialized, true);
        }
        $modified = preg_replace_callback('/(s:4:"hash";s:44:")[^"]{44}(";)/', static fn (array $match): string => $match[1].str_repeat('A', 44).$match[2], $serialized, 1, $count);
        expect($count)->toBe(1);
        DB::table('job_batches')->where('id', $batch->id)->update(['options' => $postgres ? base64_encode($modified) : $modified]);
        expect(app(BatchRepository::class)->find($batch->id)->options)->toBe([])
            ->and(DB::table('ownership_queue_probes')->where('stage', 'batch')->count())->toBe(0);
    });
});
