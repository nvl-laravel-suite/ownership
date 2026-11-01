<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\NativeDatabaseSource;
use Nvl\Ownership\Tests\Fixtures\Queue\ProbeJob;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Queue\UnownedProbeJob;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Symfony\Component\Process\Process;

it('runs explicit global jobs and native batch callbacks in a separate worker without owned restoration', function (): void {
    if (! in_array(getenv('NVL_OWNERSHIP_TEST_DRIVER'), ['pgsql', 'mysql', 'mariadb'], true)) {
        $this->markTestSkipped('Requires the explicitly dedicated native worker source.');
    }
    $this->allowOwners();
    ResourceScenario::install($this->app);
    QueueScenario::install();
    $context = app(OwnershipQueueContext::class);
    $envelope = $context->captureUnowned();
    $context->run($envelope, function () use ($context, $envelope): void {
        $context->captureBatch(Bus::batch([new UnownedProbeJob($envelope)])->onConnection('database')->then(QueueScenario::unownedBatchCallback()))->dispatch();
    });
    $raw = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): string {
        $job = new ProbeJob('forged-global-restore', ColumnAsset::query()->findOrFail(1));

        return (new ReflectionMethod(Queue::connection('database'), 'createPayload'))->invoke(Queue::connection('database'), $job, 'default');
    });
    $wire = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
    $wire['data']['nvl_ownership'] = $envelope->toArray();
    Queue::connection('database')->pushRaw(json_encode($wire, JSON_THROW_ON_ERROR));
    $worker = new Process([PHP_BINARY, __DIR__.'/../Fixtures/Queue/worker.php', json_encode(NativeDatabaseSource::capture(DB::connection('sqlite')), JSON_THROW_ON_ERROR)], timeout: 40);
    $worker->mustRun();
    expect($worker->getOutput())->toBe('worker-complete')
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('ownership_queue_probes')->where('stage', 'unowned-handle')->value('owner'))->toBe('unresolved')
        ->and(DB::table('ownership_queue_probes')->where('stage', 'unowned-batch')->value('owner'))->toBe('unresolved')
        ->and(DB::table('ownership_queue_probes')->where('label', 'forged-global-restore')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(1)
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});
