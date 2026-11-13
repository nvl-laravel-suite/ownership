<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\NativeDatabaseSource;
use Nvl\Ownership\Tests\Fixtures\Queue\LocalizedStateMail;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueLifecycleJob;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueStateProbe;
use Nvl\Ownership\Tests\Fixtures\Queue\UnownedStateJob;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Symfony\Component\Process\Process;

it('restores authority and locale between exceptional owned global and denied jobs in one worker', function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly dedicated native source and independent worker.');
    }
    $this->allowOwners([7, 8, 9]);
    Company::query()->create(['id' => 9]);
    ResourceScenario::install($this->app);
    QueueScenario::install();
    QueueStateProbe::install();
    config(['queue.default' => 'database']);
    $runner = app(OwnershipRunner::class);

    $runner->run(Company::findOrFail(7), Actor::findOrFail(1), static function (): void {
        Mail::to('worker@example.test')->queue((new LocalizedStateMail('exception-A', throws: true))->locale('bg')->onConnection('database'));
    });
    $runner->run(Company::findOrFail(8), Actor::findOrFail(1), static function (): void {
        Mail::to('worker@example.test')->queue((new LocalizedStateMail('localized-B'))->locale('ro')->onConnection('database'));
    });
    $context = app(OwnershipQueueContext::class);
    $unowned = $context->captureUnowned();
    $context->run($unowned, static function () use ($unowned): void {
        Queue::connection('database')->push(new UnownedStateJob($unowned));
    });
    $runner->run(Company::findOrFail(9), Actor::findOrFail(1), static function (): void {
        Queue::connection('database')->push(new QueueLifecycleJob('denied-owner'));
    });
    Company::findOrFail(9)->update(['active' => false]);
    $runner->run(Company::findOrFail(8), Actor::findOrFail(1), static function (): void {
        Queue::connection('database')->push(new QueueLifecycleJob('following-B'));
    });
    $rawPayloads = DB::table('jobs')->orderBy('id')->pluck('payload');
    $payloads = $rawPayloads->map(static fn (string $payload): array => json_decode($payload, true, flags: JSON_THROW_ON_ERROR));
    expect($payloads)->toHaveCount(5)->and(QueueStateProbe::observations())->toBe([]);

    $worker = new Process([
        PHP_BINARY,
        __DIR__.'/../Fixtures/Queue/sequential-worker.php',
        json_encode(NativeDatabaseSource::capture(DB::connection('sqlite')), JSON_THROW_ON_ERROR),
    ], timeout: 40);
    $worker->mustRun();
    expect($worker->getOutput())->toBe('sequential-worker-complete');

    $mail = QueueStateProbe::observations('mail');
    expect(array_column($mail, 'label'))->toBe(['exception-A', 'localized-B'])
        ->and(array_column($mail, 'owner'))->toBe(['7', '8'])
        ->and(array_column($mail, 'locale'))->toBe(['bg', 'ro']);
    foreach ($mail as $observation) {
        expect($observation)->toMatchArray([
            'mode' => 'owner', 'principal_kind' => 'actor', 'principal_type' => Actor::class,
            'principal_id' => '1', 'operation' => null,
        ]);
    }
    $failedMail = QueueStateProbe::observations('mail-failed');
    expect($failedMail)->toHaveCount(1);
    expect($failedMail[0])->toMatchArray([
        'label' => 'exception-A', 'mode' => 'owner', 'owner' => '7',
        'principal_kind' => 'actor', 'principal_type' => Actor::class, 'principal_id' => '1',
        'operation' => null, 'locale' => 'en',
    ]);

    $global = QueueStateProbe::observations('unowned');
    expect($global)->toHaveCount(1);
    expect($global[0])->toMatchArray([
        'label' => 'global', 'mode' => 'unresolved', 'owner' => null,
        'principal_kind' => null, 'principal_type' => null, 'principal_id' => null,
        'operation' => null, 'locale' => 'en',
    ]);
    expect(array_column(QueueStateProbe::observations('handle'), 'label'))->toBe(['following-B']);
    expect(QueueStateProbe::observations('handle')[0])->toMatchArray([
        'mode' => 'owner', 'owner' => '8', 'principal_kind' => 'actor',
        'principal_type' => Actor::class, 'principal_id' => '1', 'operation' => null, 'locale' => 'en',
    ]);
    expect(DB::table('ownership_queue_state_probes')->where('label', 'denied-owner')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->count())->toBe(2)
        ->and(DB::table('failed_jobs')->where('exception', 'like', '%NVL queue envelope rejected:%')->count())->toBe(1)
        ->and(DB::table('failed_jobs')->where('payload', 'like', '%denied-owner%')->value('payload'))->toBe($rawPayloads[3]);

    $attempted = QueueStateProbe::observations('attempted');
    expect(array_column($attempted, 'label'))->toBe($payloads->pluck('uuid')->all());
    foreach ($attempted as $observation) {
        expect($observation)->toMatchArray([
            'mode' => 'unresolved', 'owner' => null, 'principal_kind' => null,
            'principal_type' => null, 'principal_id' => null, 'operation' => null, 'locale' => 'en',
        ]);
    }
    $observations = QueueStateProbe::observations();
    $processes = array_unique(array_column($observations, 'process_id'));
    expect($processes)->toHaveCount(1)->and(reset($processes))->toBeGreaterThan(0)->not->toBe(getmypid());
    $handlers = array_values(array_filter($observations, static fn (array $observation): bool => in_array($observation['stage'], ['mail', 'unowned', 'handle'], true)));
    expect(array_column($handlers, 'label'))->toBe(['exception-A', 'localized-B', 'global', 'following-B']);
});
