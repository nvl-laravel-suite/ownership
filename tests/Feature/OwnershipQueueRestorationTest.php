<?php

declare(strict_types=1);

use Illuminate\Console\OutputStyle;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\OptsIntoAdoptedSchemaReuse;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeJob;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Console\Commands\QueueQuarantineRetryCommand;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\BufferedOutput;

uses(OptsIntoAdoptedSchemaReuse::class);

beforeEach(function (): void {
    if (in_array('queue-input-only', $this->groups(), true)) {
        return;
    }
    $this->allowOwners();
    ResourceScenario::installColumn($this->app, $this->hasRetainedOwnershipSchema());
    $this->retainAdoptedOwnershipSchema(ResourceScenario::ColumnSeedTables);
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

it('raw retries ordinary captured failures without eager native model restoration', function (): void {
    QueueScenario::install();
    $payload = ownershipProbePayload(ColumnAsset::withoutGlobalScopes()->findOrFail(1));
    $id = app('queue.failer')->log('database', 'default', $payload, new RuntimeException('ordinary business failure'));
    ProbeJob::$observations = [];
    expect(fn () => Artisan::call('queue:retry', ['id' => [$id]]))->toThrow(OwnershipViolation::class, 'nvl:queue:retry');
    expect(ProbeJob::$observations)->toBe([]);
    expect(Artisan::call('nvl:queue:retry', ['id' => [$id]]))->toBe(0);
    expect(ProbeJob::$observations)->toBe([])->and(DB::table('jobs')->value('payload'))->toBe($payload);
})->group('fresh-adoption');

/** Observes every native restoration entry without implementing the new ownership protocol. */
final class RetiredOwnershipHostJob implements ShouldQueue
{
    /** @var list<string> */
    public static array $observations = [];

    public int $tries = 1;

    /** @param array<string, mixed> $values */
    public function __unserialize(array $values): void
    {
        self::$observations[] = 'restore';
    }

    /** Observe normal host execution. */
    public function handle(): void
    {
        self::$observations[] = 'handle';
    }

    /** Observe terminal host execution. */
    public function failed(?Throwable $exception): void
    {
        self::$observations[] = 'failed';
    }
}

it('quarantines retired-only authority on enabled workers retaining a native host handler', function (bool $terminal): void {
    QueueScenario::install();
    RetiredOwnershipHostJob::$observations = [];
    $handler = new CallQueuedHandler(app(Dispatcher::class), app());
    app()->instance(CallQueuedHandler::class, $handler);
    $queue = Queue::connection('database');
    $wire = ['uuid' => (string) Str::uuid(), 'displayName' => RetiredOwnershipHostJob::class, 'job' => CallQueuedHandler::class.'@call', 'maxTries' => 1, 'data' => ['commandName' => RetiredOwnershipHostJob::class, 'command' => serialize(new RetiredOwnershipHostJob)]];
    $wire['data']['nvl_tenancy'] = ['version' => 1, 'mode' => 'tenant', 'tenant_id' => 'retired-owner'];
    unset($wire['data']['nvl_ownership']);
    $raw = json_encode($wire, JSON_THROW_ON_ERROR);
    $id = $queue->pushRaw($raw);
    DB::table('jobs')->where('id', $id)->update(['attempts' => $terminal ? 1 : 0]);

    if ($terminal) {
        expect(fn () => app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1)))
            ->toThrow(MaxAttemptsExceededException::class);
    } else {
        app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
    }

    expect(app(CallQueuedHandler::class))->toBe($handler)
        ->and(RetiredOwnershipHostJob::$observations)->toBe([])
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->value('payload'))->toBe($raw)
        ->and(DB::table('failed_jobs')->value('exception'))->toContain('NVL queue envelope rejected:');
})->with(['normal worker' => false, 'terminal worker' => true])->group('fresh-adoption');

it('rejects malformed raw retry identifiers before changing failed-job storage', function (mixed $ids): void {
    $input = Mockery::mock(InputInterface::class);
    $input->shouldReceive('getArgument')->with('id')->andReturn($ids);
    $output = new BufferedOutput;
    $command = app(QueueQuarantineRetryCommand::class);
    $command->setLaravel(app());
    $command->setInput($input);
    $command->setOutput(new OutputStyle($input, $output));

    expect(app()->call([$command, 'handle']))->toBe(1)
        ->and($output->fetch())->toContain('Native failed-job IDs must be a nonempty list of strings.');
})->with(['scalar' => ['not-a-list'], 'empty list' => [[]], 'invalid entry' => [[new stdClass]]])
    ->group('queue-input-only', 'fresh-adoption');
