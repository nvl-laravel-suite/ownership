<?php

declare(strict_types=1);

use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Queue\WorkerOptions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Services\OwnershipConfiguration;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\ScopedOwnershipContext;
use Nvl\Ownership\Tests\Fixtures\Adoption\LargeGraphFixture;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Queue\DisabledPublicationJob;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Support\Console\Commands\QueueQuarantineRetryCommand;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext as QueueContextContract;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\DisabledOwnershipQueueContext;
use Nvl\Support\Ownership\Services\OwnershipQueueQuarantine;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

beforeEach(function (): void {
    LargeGraphFixture::register();
    $resources = new OwnershipResourceRegistry;
    foreach (app(OwnershipResourceRegistry::class)->all() as $resource) {
        $resources->register(new OwnershipResourceDefinition($resource->key, $resource->family, $resource->model, OwnershipDefinition::unscoped()));
    }
    $this->app->instance(OwnershipResourceRegistry::class, $resources);
    config(['nvl-ownership.enabled' => false]);
    app(ScopedOwnershipContext::class)->replace(new OwnershipSnapshot(OwnershipContextMode::Disabled));
    QueueScenario::install();
});

it('bounds cold and warm publication probes with the native runtime installed but disabled', function (): void {
    $catalogs = 0;
    DB::listen(static function (QueryExecuted $query) use (&$catalogs): void {
        if (str_contains($query->sql, ' as protocol')) {
            $catalogs++;
        }
    });
    $counts = [];
    foreach (['cold', 'warm'] as $identity) {
        $catalogs = 0;
        Queue::connection('database')->push(new DisabledPublicationJob($identity));
        $counts[] = $catalogs;
    }
    $payloads = DB::table('jobs')->pluck('payload')->map(static fn (string $wire): array => json_decode($wire, true, flags: JSON_THROW_ON_ERROR));
    expect($counts)->toBe([1, 1])
        ->and($payloads)->toHaveCount(2)
        ->and($payloads->pluck('data.nvl_ownership.mode')->all())->toBe(['disabled', 'disabled'])
        ->and(DB::table('ownership_queue_probes')->count())->toBe(0);
});

it('restores the exact preceding context after successful exceptional and nested disabled callbacks', function (): void {
    $context = app(ScopedOwnershipContext::class);
    $preceding = new OwnershipSnapshot(OwnershipContextMode::Unresolved);
    $context->replace($preceding);
    $envelope = OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
    $queue = app(OwnershipQueueContext::class);
    $catalogs = 0;
    DB::listen(static function (QueryExecuted $query) use (&$catalogs): void {
        if (str_contains($query->sql, ' as protocol')) {
            $catalogs++;
        }
    });
    $queue->run($envelope, function () use ($queue, $envelope, $context): void {
        $outer = $context->snapshot();
        expect($outer->mode)->toBe(OwnershipContextMode::Disabled);
        expect(fn () => $queue->run($envelope, static fn () => throw new RuntimeException('nested callback')))->toThrow(RuntimeException::class, 'nested callback');
        expect($context->snapshot())->toBe($outer);
    });
    expect($context->snapshot())->toBe($preceding)->and($catalogs)->toBe(2);
    expect(fn () => $queue->run($envelope, static fn () => throw new RuntimeException('outer callback')))->toThrow(RuntimeException::class, 'outer callback');
    expect($context->snapshot())->toBe($preceding)->and($catalogs)->toBe(3);
});

it('refuses disabled capture as soon as a native owned declaration appears', function (): void {
    $command = new DisabledPublicationJob('stale');
    $resources = app(OwnershipResourceRegistry::class);
    $first = $resources->get('scale-1.record_1');
    $owned = new OwnershipResourceRegistry;
    $owned->register(new OwnershipResourceDefinition($first->key, $first->family, $first->model, OwnershipDefinition::column(Company::class, 'company_id')));
    $this->app->instance(OwnershipResourceRegistry::class, $owned);
    expect(fn () => Queue::connection('database')->push($command))->toThrow(OwnershipViolation::class)
        ->and(DB::table('jobs')->count())->toBe(0);
});

it('executes a genuinely captured disabled job through the native synchronous transport', function (): void {
    Queue::connection('sync')->push(new DisabledPublicationJob('sync'));
    expect(DB::table('ownership_queue_probes')->pluck('owner')->all())->toBe(['disabled'])
        ->and(app(ScopedOwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Disabled);
});

it('captures unowned work and native batches exactly like an absent runtime', function (): void {
    $queue = app(QueueContextContract::class);
    $batch = Bus::batch([]);
    expect($queue)->toBeInstanceOf(OwnershipQueueContext::class)
        ->and($queue->captureUnowned()->toArray())->toBe(app(DisabledOwnershipQueueContext::class)->captureUnowned()->toArray())
        ->and($queue->captureUnowned()->context()->mode)->toBe(OwnershipContextMode::Disabled)
        ->and($queue->captureBatch($batch))->toBe($batch)
        ->and($batch->options)->not->toHaveKey('nvl_ownership');
});

it('natively retries an ordinary disabled failure and keeps raw retry for quarantined records', function (): void {
    $queue = Queue::connection('database');
    $queue->push(new DisabledPublicationJob('retry'));
    $payload = DB::table('jobs')->value('payload');
    DB::table('jobs')->delete();
    $ordinary = app('queue.failer')->log('database', 'default', $payload, new RuntimeException('The mail transport is unavailable.'));

    expect(Artisan::call('queue:retry', ['id' => [$ordinary]]))->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(1);
    app('queue.worker')->process('database', $queue->pop(), new WorkerOptions(maxTries: 1));
    $quarantined = app('queue.failer')->log('database', 'default', $payload, new OwnershipViolation(OwnershipQueueQuarantine::REJECTION_PREFIX.'Storage was unavailable.'));
    expect(DB::table('ownership_queue_probes')->pluck('label')->all())->toBe(['retry'])
        ->and(fn () => Artisan::call('queue:retry', ['id' => [$quarantined]]))->toThrow(OwnershipViolation::class, 'nvl:queue:retry')
        ->and(Artisan::call('nvl:queue:retry', ['id' => [$quarantined]]))->toBe(0)
        ->and(DB::table('jobs')->value('payload'))->toBe($payload)
        ->and(DB::table('failed_jobs')->count())->toBe(0);
});

it('leaves raw retry to the Core command and registers nothing of its own when the installed runtime boots disabled', function (): void {
    expect(Artisan::all()['nvl:queue:retry'] ?? null)->toBeInstanceOf(QueueQuarantineRetryCommand::class);

    ConsoleApplication::forgetBootstrappers();
    (new OwnershipServiceProvider(app()))->boot(app(OwnershipConfiguration::class));
    $artisan = new ConsoleApplication(app(), app('events'), app()->version());
    expect($artisan->has('nvl:queue:retry'))->toBeFalse()
        ->and($artisan->has('nvl:ownership:doctor'))->toBeFalse();
});
