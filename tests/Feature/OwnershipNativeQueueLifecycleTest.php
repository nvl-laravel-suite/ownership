<?php

declare(strict_types=1);

use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Contracts\OwnerSiteResolver;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\PublicOwnerSiteRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueLifecycleJob;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueStateProbe;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext;

uses(OptsIntoAdoptedSchemaReuse::class);

beforeEach(function (): void {
    $this->allowOwners();
    if (! $this->hasRetainedOwnershipSchema()) {
        QueueStateProbe::install();
    }
    $this->installColumnScenario(queue: true);
    config(['queue.default' => 'database']);
    app()->setLocale('en');
});

it('executes a native same-owner chain and restores the surrounding owner after each job', function (): void {
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), static function (): void {
        Bus::chain([new QueueLifecycleJob('chain-first'), new QueueLifecycleJob('chain-second')])
            ->onConnection('database')->dispatch();
    });
    expect(DB::table('jobs')->count())->toBe(1);

    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
        $previous = app(OwnershipContext::class)->snapshot();
        $queue = Queue::connection('database');
        foreach ([1, 2] as $unused) {
            $job = $queue->pop();
            expect($job)->not->toBeNull();
            app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
            expect(app(OwnershipContext::class)->snapshot())->toBe($previous)
                ->and(app()->getLocale())->toBe('en');
        }
    });

    $restored = QueueStateProbe::observations('restore');
    expect(array_values(array_unique(array_column($restored, 'label'))))->toBe(['chain-first', 'chain-second']);
    foreach ($restored as $observation) {
        expect($observation)->toMatchArray([
            'stage' => 'restore', 'mode' => 'owner', 'owner' => '7',
            'principal_kind' => 'actor', 'principal_type' => Actor::class, 'principal_id' => '1',
            'operation' => null, 'locale' => 'en', 'process_id' => (int) getmypid(),
        ]);
    }
    $handled = QueueStateProbe::observations('handle');
    expect(array_column($handled, 'label'))->toBe(['chain-first', 'chain-second'])
        ->and(array_column($handled, 'owner'))->toBe(['7', '7'])
        ->and(array_column($handled, 'principal_kind'))->toBe(['actor', 'actor'])
        ->and(array_column($handled, 'principal_id'))->toBe(['1', '1'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('quarantines a mixed-owner native chain before restoring either command', function (): void {
    $foreign = app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), static fn (): QueueLifecycleJob => new QueueLifecycleJob('foreign-chain-tail'));
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), static function () use ($foreign): void {
        Bus::chain([new QueueLifecycleJob('owned-chain-head'), $foreign])->onConnection('database')->dispatch();
    });
    $raw = DB::table('jobs')->sole()->payload;
    expect(DB::table('jobs')->count())->toBe(1)->and(QueueStateProbe::observations())->toBe([]);

    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
        $previous = app(OwnershipContext::class)->snapshot();
        $job = Queue::connection('database')->pop();
        expect($job)->not->toBeNull();
        app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
        expect(app(OwnershipContext::class)->snapshot())->toBe($previous);
    });

    expect(QueueStateProbe::observations())->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->sole()->payload)->toBe($raw)
        ->and(DB::table('failed_jobs')->sole()->exception)->toContain('NVL queue envelope rejected:');
});

it('restores owner and principal around native batch success and failure callbacks', function (bool $fails): void {
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), static function () use ($fails): void {
        $batch = Bus::batch([new QueueLifecycleJob('batched-job', throws: $fails)])
            ->onConnection('database')->allowFailures()
            ->then(QueueStateProbe::batchCallback('then', 'batch-callback'))
            ->catch(QueueStateProbe::batchFailureCallback('batch-callback'))
            ->finally(QueueStateProbe::batchCallback('finally', 'batch-callback'));
        app(OwnershipQueueContext::class)->captureBatch($batch)->dispatch();
    });
    $payload = json_decode(DB::table('jobs')->sole()->payload, true, flags: JSON_THROW_ON_ERROR);

    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function () use ($fails): void {
        $previous = app(OwnershipContext::class)->snapshot();
        $job = Queue::connection('database')->pop();
        expect($job)->not->toBeNull();
        $process = static fn () => app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
        if ($fails) {
            expect($process)->toThrow(RuntimeException::class, 'Queue lifecycle fixture failure.');
        } else {
            $process();
        }
        expect($job->hasFailed())->toBe($fails)
            ->and($job->isDeleted())->toBeTrue()
            ->and(app(OwnershipContext::class)->snapshot())->toBe($previous)
            ->and(app()->getLocale())->toBe('en');
    });

    $callbacks = array_values(array_filter(QueueStateProbe::observations(), static fn (array $observation): bool => $observation['label'] === 'batch-callback'));
    expect(array_column($callbacks, 'stage'))->toBe($fails ? ['catch', 'finally'] : ['then', 'finally']);
    foreach ($callbacks as $callback) {
        expect($callback)->toMatchArray([
            'mode' => 'owner', 'owner' => '7', 'principal_kind' => 'actor',
            'principal_type' => Actor::class, 'principal_id' => '1', 'operation' => null, 'locale' => 'en',
        ]);
    }
    $terminal = QueueStateProbe::observations('failed');
    expect($terminal)->toHaveCount($fails ? 1 : 0);
    foreach ($terminal as $observation) {
        expect($observation)->toMatchArray([
            'stage' => 'failed', 'label' => 'batched-job', 'mode' => 'owner', 'owner' => '7',
            'principal_kind' => 'actor', 'principal_type' => Actor::class, 'principal_id' => '1',
            'operation' => null, 'locale' => 'en', 'process_id' => (int) getmypid(),
        ]);
    }
    $storedBatch = DB::table('job_batches')->sole();
    expect($storedBatch->failed_jobs)->toBe($fails ? 1 : 0)
        ->and(json_decode($storedBatch->failed_job_ids, true, flags: JSON_THROW_ON_ERROR))->toBe($fails ? [$payload['uuid']] : [])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(app(OwnershipContext::class)->snapshot()->principal)->toBeNull();
})->with(['successful batch' => false, 'failed batch' => true]);

it('uses native overlap locks for the same business identity within one owner while isolating another', function (): void {
    config([
        'cache.default' => 'database',
        'cache.stores.database.connection' => 'sqlite',
        'cache.stores.database.lock_connection' => 'sqlite',
        'cache.stores.database.table' => 'cache',
        'cache.stores.database.lock_table' => 'cache_locks',
    ]);
    app()->forgetInstance('cache.store');
    $ownerA = app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), static fn (): QueueLifecycleJob => new QueueLifecycleJob('overlap-blocked-A', overlap: true));
    $sameOwner = app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), static fn (): QueueLifecycleJob => new QueueLifecycleJob('overlap-released-A', overlap: true));
    $ownerB = app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), static fn (): QueueLifecycleJob => new QueueLifecycleJob('overlap-isolated-B', overlap: true));
    $key = $ownerA->ownershipLockKey(QueueLifecycleJob::OVERLAP_IDENTITY);
    expect($key)->toBe($sameOwner->ownershipLockKey(QueueLifecycleJob::OVERLAP_IDENTITY))
        ->not->toBe($ownerB->ownershipLockKey(QueueLifecycleJob::OVERLAP_IDENTITY));
    $middleware = new WithoutOverlapping($key);
    $lock = Cache::lock($middleware->getLockKey($ownerA), 60);
    expect($lock->get())->toBeTrue();
    try {
        $queue = Queue::connection('database');
        $queue->push($ownerA);
        $queue->push($ownerB);
        foreach ([1, 2] as $unused) {
            $job = $queue->pop();
            expect($job)->not->toBeNull();
            app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
        }
        expect(array_column(QueueStateProbe::observations('handle'), 'label'))->toBe(['overlap-isolated-B']);
    } finally {
        $lock->release();
    }
    Queue::connection('database')->push($sameOwner);
    $job = Queue::connection('database')->pop();
    expect($job)->not->toBeNull();
    app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
    expect(array_column(QueueStateProbe::observations('handle'), 'label'))->toBe(['overlap-isolated-B', 'overlap-released-A'])
        ->and(array_column(QueueStateProbe::observations('handle'), 'owner'))->toBe(['8', '7'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(app(OwnershipContext::class)->snapshot()->principal)->toBeNull();
});

it('publishes enabled captured work only after the outer commit and discards rolled back delivery', function (): void {
    app(OwnershipRunner::class)->run(Company::findOrFail(7), Actor::findOrFail(1), static function (): void {
        DB::beginTransaction();
        try {
            DB::transaction(static function (): void {
                Queue::connection('database')->push((new QueueLifecycleJob('outer-committed'))->afterCommit());
            });
            expect(DB::transactionLevel())->toBe(1)
                ->and(DB::table('jobs')->count())->toBe(0)
                ->and(QueueStateProbe::observations('handle'))->toBe([]);
            DB::commit();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        expect(DB::table('jobs')->count())->toBe(1);
        DB::beginTransaction();
        try {
            DB::transaction(static function (): void {
                Queue::connection('database')->push((new QueueLifecycleJob('outer-rolled-back'))->afterCommit());
            });
            expect(DB::transactionLevel())->toBe(1)->and(DB::table('jobs')->count())->toBe(1);
            DB::rollBack();
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
        }
        expect(DB::table('jobs')->count())->toBe(1);
    });
    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
        $previous = app(OwnershipContext::class)->snapshot();
        $job = Queue::connection('database')->pop();
        expect($job)->not->toBeNull();
        app('queue.worker')->process('database', $job, new WorkerOptions(maxTries: 1));
        expect(app(OwnershipContext::class)->snapshot())->toBe($previous);
    });
    expect(array_column(QueueStateProbe::observations('handle'), 'label'))->toBe(['outer-committed'])
        ->and(array_column(QueueStateProbe::observations('handle'), 'owner'))->toBe(['7'])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('refuses actor and global queue capture inside freshly verified public admission', function (): void {
    $site = new VerifiedOwnerSiteContext(app(OwnerIdentityResolver::class)->reference(Company::findOrFail(7)), 'storefront', 'https://queue.public.test', ['assets.column']);
    app()->instance(OwnerSiteResolver::class, new class($site) implements OwnerSiteResolver
    {
        public function __construct(private readonly VerifiedOwnerSiteContext $site) {}

        public function resolve(string $origin): ?VerifiedOwnerSiteContext
        {
            return $origin === $this->site->canonicalOrigin ? $this->site : null;
        }
    });
    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::findOrFail(1), function (): void {
        $previous = app(OwnershipContext::class)->snapshot();
        app(PublicOwnerSiteRunner::class)->run('https://queue.public.test', static function (): void {
            $public = app(OwnershipContext::class)->snapshot();
            expect($public->principal['kind'] ?? null)->toBe('public')
                ->and(fn () => OwnershipJobEnvelope::capture(app(OwnershipContext::class)))->toThrow(OwnershipViolation::class)
                ->and(fn () => app(OwnershipQueueContext::class)->captureUnowned())->toThrow(OwnershipViolation::class)
                ->and(app(OwnershipContext::class)->snapshot())->toBe($public);
        });
        expect(app(OwnershipContext::class)->snapshot())->toBe($previous);
    });
    expect(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(0)
        ->and(app(OwnershipContext::class)->snapshot()->principal)->toBeNull();
});
