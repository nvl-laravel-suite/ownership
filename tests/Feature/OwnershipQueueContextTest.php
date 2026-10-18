<?php

declare(strict_types=1);

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
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

it('re-admits captured owners and restores a different surrounding worker scope on failure', function (): void {
    $envelope = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn (): OwnershipJobEnvelope => OwnershipJobEnvelope::capture(app(OwnershipContext::class)));
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(8), Actor::query()->firstOrFail(), function () use ($envelope): void {
        expect(app(OwnershipQueueContext::class)->run($envelope, fn (): array => ColumnAsset::query()->pluck('name')->all()))->toBe(['private-7']);
        expect(fn () => app(OwnershipQueueContext::class)->run($envelope, static fn () => throw new RuntimeException('job failure')))->toThrow(RuntimeException::class);
        expect(ColumnAsset::query()->pluck('name')->all())->toBe(['private-8']);
    });
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('reloads the native actor before applying current host admission policy', function (): void {
    $envelope = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn (): OwnershipJobEnvelope => OwnershipJobEnvelope::capture(app(OwnershipContext::class)));
    $this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess
    {
        public function assertCanEnter(Authenticatable $actor, Model $owner): void
        {
            if (! $actor instanceof Actor || $actor->name !== 'Operator') {
                throw new OwnershipViolation;
            }
        }
    });
    Actor::query()->firstOrFail()->forceFill(['name' => 'revoked'])->save();
    expect(fn () => app(OwnershipQueueContext::class)->run($envelope, static fn () => 'unsafe'))->toThrow(OwnershipViolation::class)
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('rejects stale installation generations and disabled workers before their callback', function (): void {
    $wire = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn (): array => OwnershipJobEnvelope::capture(app(OwnershipContext::class))->toArray());
    foreach ($wire['generations'] as $source => $generation) {
        $wire['generations'][$source] = 'stale';
    }
    expect(fn () => app(OwnershipQueueContext::class)->run(OwnershipJobEnvelope::fromArray($wire), static fn () => 'unsafe'))->toThrow(OwnershipSchemaNotReady::class);
    config()->set('nvl-ownership.enabled', false);
    expect(fn () => app(OwnershipQueueContext::class)->run(OwnershipJobEnvelope::fromArray($wire), static fn () => 'unsafe'))->toThrow(OwnershipViolation::class);
});

it('rejects actor source drift even when another store has the same native actor id', function (): void {
    $envelope = app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn (): OwnershipJobEnvelope => OwnershipJobEnvelope::capture(app(OwnershipContext::class)));
    $connection = getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql' ? config('database.connections.ownership_remote') : config('database.connections.sqlite');
    config()->set('database.connections.ownership_actor_remote', $connection);
    $remote = DB::connection('ownership_actor_remote');
    $remote->getSchemaBuilder()->create('ownership_test_actors', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('name');
    });
    $remote->table('ownership_test_actors')->insert(['id' => 1, 'name' => 'Operator']);
    config()->set('ownership-test.actor-connection', 'ownership_actor_remote');
    expect(fn () => app(OwnershipQueueContext::class)->run($envelope, static fn () => 'unsafe'))->toThrow(OwnershipSchemaNotReady::class);
});

it('revalidates named system authorization and its deployment allowlist on workers', function (): void {
    config()->set('nvl-ownership.system_operations', ['media.generate']);
    $this->app->bind(OwnerSystemAccess::class, static fn () => new class implements OwnerSystemAccess
    {
        public function assertCanRun(string $operation, Model $owner): void
        {
            if ($operation !== 'media.generate' || $owner->getKey() !== 7) {
                throw new OwnershipViolation;
            }
        }
    });
    $reference = app(OwnerIdentityResolver::class)->reference(Company::query()->findOrFail(7));
    $envelope = app(OwnershipRunner::class)->runSystem($reference, 'media.generate', fn (): OwnershipJobEnvelope => OwnershipJobEnvelope::capture(app(OwnershipContext::class)));
    expect(app(OwnershipQueueContext::class)->run($envelope, fn (): string => app(OwnershipContext::class)->requireOwner()->key))->toBe('7')
        ->and(DB::table('nvl_ownership_operation_facts')->where('purpose', 'media.generate')->count())->toBe(2);
    config()->set('nvl-ownership.system_operations', []);
    expect(fn () => app(OwnershipQueueContext::class)->run($envelope, static fn () => 'unsafe'))->toThrow(OwnershipViolation::class)
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});
