<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Boots only host policy and the same immutable fixture graph in a separate process. */
final class WorkerProvider extends ServiceProvider
{
    /** @var array<array-key, mixed> */
    private static array $source = [];

    /** @var array<array-key, mixed> */
    private static array $options = [];

    /** @param array<array-key, mixed> $connection
     * @param  array<array-key, mixed>  $options
     */
    public static function useSource(array $connection, array $options = []): void
    {
        self::$source = $connection;
        self::$options = $options;
    }

    /** Bind native host policies independently of a producer's request objects. */
    public function register(): void
    {
        $this->app->make('config')->set([
            'app.key' => 'base64:YWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWFhYWE=',
            'database.default' => 'sqlite',
            'database.connections.sqlite' => self::$source,
            'nvl-core.owners' => [Company::class, Actor::class],
            'nvl-ownership.enabled' => true,
            'nvl-ownership.migrations.enabled' => false,
            'nvl-tenancy.enabled' => false,
            'queue.default' => self::$options['driver'] ?? 'database',
            'ownership-worker.driver' => self::$options['driver'] ?? 'database',
            'queue.connections.database.connection' => 'sqlite',
            'queue.batching.database' => 'sqlite',
            'queue.failed' => ['driver' => 'database-uuids', 'database' => 'sqlite', 'table' => 'failed_jobs'],
            'cache.default' => (self::$options['driver'] ?? 'database') === 'redis' ? 'redis' : 'database',
            'cache.prefix' => self::$options['prefix'] ?? 'ownership-worker-proof:',
            'cache.stores.database.connection' => 'sqlite',
            'cache.stores.database.lock_connection' => 'sqlite',
            'cache.stores.database.table' => 'cache',
            'cache.stores.database.lock_table' => 'cache_locks',
            'cache.stores.redis.connection' => 'ownership_worker',
            'cache.stores.redis.lock_connection' => 'ownership_worker',
            'database.redis.client' => 'phpredis',
            'database.redis.options.prefix' => self::$options['prefix'] ?? 'ownership-worker-proof:',
            'database.redis.ownership_worker' => ['host' => '127.0.0.1', 'port' => self::$options['redis_port'] ?? 16389, 'database' => 0],
            'queue.connections.redis.connection' => 'ownership_worker',
            'queue.connections.redis.queue' => 'default',
            'queue.connections.redis.retry_after' => 10,
            'mail.default' => 'array',
            'mail.mailers.array.transport' => 'array',
        ]);
        $this->app->bind(OwnerAccess::class, static fn (): OwnerAccess => new class implements OwnerAccess
        {
            /** Admit only the fresh native fixture actor. */
            public function assertCanEnter(Authenticatable $actor, Model $owner): void
            {
                if (! $actor instanceof Actor || $actor->getAuthIdentifier() !== 1 || $actor->name !== 'Operator' || ! $owner instanceof Company) {
                    throw new OwnershipViolation;
                }
            }
        });
        $this->app->bind(OwnerAvailability::class, static fn (): OwnerAvailability => new class implements OwnerAvailability
        {
            /** Recheck native availability on every worker boundary. */
            public function assertAvailable(Model $owner): void
            {
                if (! $owner->exists || $owner->getAttribute('active') !== true) {
                    throw new OwnershipViolation;
                }
            }
        });
    }

    /** Register code metadata without installing schema or inventing readiness markers. */
    public function boot(): void
    {
        ResourceScenario::register(Container::getInstance());
        $this->app->make('events')->listen(ProbeEvent::class, ProbeListener::class);
        $this->app->make('events')->listen(JobProcessed::class, static function (JobProcessed $event): void {
            DB::table('ownership_queue_probes')->insert(['stage' => 'worker-after', 'owner' => app(OwnershipContext::class)->snapshot()->mode->value, 'label' => $event->job->uuid() ?? 'native']);
        });
    }
}
