<?php

declare(strict_types=1);

use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Foundation\Bus\PendingDispatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeEvent;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeJob;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeListener;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeMail;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeNotification;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Queue\UniqueJob;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Symfony\Component\Process\Process;

it('preserves captured ownership through a real worker retry exhaustion and raw quarantine', function (string $driver): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly isolated PostgreSQL persistence database.');
    }
    $redisPort = getenv('NVL_OWNERSHIP_TEST_REDIS_PORT');
    if ($driver === 'redis' && (! is_string($redisPort) || ! ctype_digit($redisPort))) {
        $this->markTestSkipped('Requires an explicitly dedicated Redis test port.');
    }
    $options = ['driver' => $driver, 'prefix' => 'nvl-own-worker:'.bin2hex(random_bytes(6)).':', 'redis_port' => is_string($redisPort) ? (int) $redisPort : 16389];
    $this->allowOwners();
    ResourceScenario::install($this->app);
    QueueScenario::install();
    config()->set([
        'queue.default' => $driver,
        'ownership-worker.driver' => $driver,
        'cache.default' => $driver === 'redis' ? 'redis' : 'database',
        'cache.prefix' => $options['prefix'],
        'cache.stores.database.connection' => 'sqlite',
        'cache.stores.database.lock_connection' => 'sqlite',
        'cache.stores.database.table' => 'cache',
        'cache.stores.database.lock_table' => 'cache_locks',
        'cache.stores.redis.connection' => 'ownership_worker',
        'cache.stores.redis.lock_connection' => 'ownership_worker',
        'database.redis.client' => 'phpredis',
        'database.redis.options.prefix' => $options['prefix'],
        'database.redis.ownership_worker' => ['host' => '127.0.0.1', 'port' => $options['redis_port'], 'database' => 0],
        'queue.connections.redis.connection' => 'ownership_worker',
        'queue.connections.redis.queue' => 'default',
        'queue.connections.redis.retry_after' => 10,
    ]);
    app()->forgetInstance('cache.store');
    $queue = Queue::connection($driver);
    foreach ([[7, 'valid-A', false, 1], [8, 'valid-B', false, 1], [7, 'retry-A', true, 2], [7, 'failure-A', true, 1], [7, 'exhausted-A', false, 1], [7, 'malformed', false, 1]] as [$owner, $label, $throws, $tries]) {
        $raw = app(OwnershipRunner::class)->run(Company::query()->findOrFail($owner), Actor::query()->firstOrFail(), function () use ($label, $owner, $throws, $tries, $queue): string {
            $job = new ProbeJob($label, ColumnAsset::query()->findOrFail($owner === 7 ? 1 : 2), $throws);
            $job->tries = $tries;

            return (new ReflectionMethod($queue, 'createPayload'))->invoke($queue, $job, 'default');
        });
        $wire = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        if ($label === 'malformed') {
            $wire['data']['nvl_ownership']['version'] = 99;
        }
        if (in_array($label, ['malformed', 'exhausted-A'], true) && $driver === 'redis') {
            $wire['attempts'] = 1;
        }
        $id = $queue->pushRaw(json_encode($wire, JSON_THROW_ON_ERROR));
        if (in_array($label, ['malformed', 'exhausted-A'], true) && $driver === 'database') {
            DB::table('jobs')->where('id', $id)->update(['attempts' => 1]);
        }
    }
    Event::listen(ProbeEvent::class, ProbeListener::class);
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use ($driver): void {
        Mail::to('ownership-proof@example.test')->queue((new ProbeMail)->onConnection($driver));
        Notification::route('mail', 'ownership-proof@example.test')->notify((new ProbeNotification)->onConnection($driver));
        Event::dispatch(new ProbeEvent);
        app(OwnershipQueueContext::class)->captureBatch(Bus::batch([new ProbeJob('batch-A')])->onConnection($driver)->then(QueueScenario::batchCallback()))->dispatch();
    });
    foreach ([7, 8] as $owner) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($owner), Actor::query()->firstOrFail(), static function (): void {
            $pending = new PendingDispatch(new UniqueJob('native-lock-proof'));
            unset($pending);
        });
    }
    $worker = new Process([PHP_BINARY, __DIR__.'/../Fixtures/Queue/worker.php', json_encode(config('database.connections.sqlite'), JSON_THROW_ON_ERROR), json_encode($options, JSON_THROW_ON_ERROR)], timeout: 40);
    $worker->mustRun();
    expect($worker->getOutput())->toBe('worker-complete');
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('ownership_queue_probes')->where('stage', 'handle')->where('label', 'valid-A')->value('owner'))->toBe('7')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'handle')->where('label', 'valid-B')->value('owner'))->toBe('8')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'handle')->where('label', 'retry-A')->count())->toBe(2)
        ->and(DB::table('ownership_queue_probes')->where('stage', 'failed')->where('label', 'failure-A')->value('owner'))->toBe('7')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'failed')->where('label', 'exhausted-A')->value('owner'))->toBe('7')
        ->and(DB::table('ownership_queue_probes')->where('label', 'malformed')->count())->toBe(0)
        ->and(DB::table('ownership_queue_probes')->where('stage', 'worker-after')->where('owner', '<>', 'unresolved')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(3)
        ->and(DB::table('failed_jobs')->where('exception', 'like', '%NVL queue envelope rejected:%')->count())->toBe(1)
        ->and(DB::table('ownership_queue_probes')->where('stage', 'mail')->value('owner'))->toBe('7')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'notification')->value('owner'))->toBe('7')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'listener')->value('owner'))->toBe('7')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'batch')->value('owner'))->toBe('7')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'handle')->where('label', 'native-lock-proof')->orderBy('owner')->pluck('owner')->all())->toBe(['7', '8']);
    foreach ([7, 8] as $owner) {
        app(OwnershipRunner::class)->run(Company::query()->findOrFail($owner), Actor::query()->firstOrFail(), static function (): void {
            $lock = new UniqueLock(app(CacheRepository::class));
            $job = new UniqueJob('native-lock-proof');
            expect($lock->acquire($job))->toBeTrue('The worker must release the same captured native uniqueness key.');
            $lock->release($job);
        });
    }
})->with(['database', 'redis']);
