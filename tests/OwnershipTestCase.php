<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Queue\QueueScenario;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Ownership\Tests\Fixtures\ReusesAdoptedSchema;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase;

/** Boots only the neutral Core and optional Ownership runtime against native host keys. */
abstract class OwnershipTestCase extends TestCase
{
    use ReusesAdoptedSchema;

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [
            SupportServiceProvider::class,
            ...(class_exists(OwnershipServiceProvider::class) ? [OwnershipServiceProvider::class] : []),
        ];
    }

    /** Configure explicitly owned native models without Tenancy or Auth providers. */
    protected function defineEnvironment($app): void
    {
        $app['config']->set([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'database.connections.sqlite.url' => null,
            'nvl-core.owners' => [Company::class, Actor::class],
            'nvl-ownership.enabled' => true,
            'nvl-ownership.migrations.enabled' => false,
            'nvl-tenancy.enabled' => false,
        ]);
        $driver = getenv('NVL_OWNERSHIP_TEST_DRIVER');
        if ($driver === false || $driver === 'sqlite') {
            return;
        }
        if ($driver !== 'pgsql') {
            throw new \RuntimeException('Unknown dedicated ownership test driver.');
        }
        $primary = getenv('NVL_OWNERSHIP_TEST_DATABASE');
        $remote = getenv('NVL_OWNERSHIP_TEST_REMOTE_DATABASE');
        foreach ([$primary, $remote] as $database) {
            if (! is_string($database) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1) {
                throw new \RuntimeException('Persistence tests require explicitly dedicated nvl_own_test_ databases.');
            }
        }
        if ($primary === $remote) {
            throw new \RuntimeException('Persistence tests require distinct primary and remote databases.');
        }
        $connection = ['driver' => 'pgsql', 'host' => getenv('NVL_OWNERSHIP_TEST_HOST') ?: '/tmp', 'port' => getenv('NVL_OWNERSHIP_TEST_PORT') ?: '5432', 'database' => $primary, 'username' => getenv('NVL_OWNERSHIP_TEST_USERNAME') ?: get_current_user(), 'password' => getenv('NVL_OWNERSHIP_TEST_PASSWORD') ?: '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer', 'timezone' => 'UTC'];
        $app['config']->set('database.connections.sqlite', ['url' => null, ...$connection]);
        $app['config']->set('database.connections.ownership_remote', ['url' => null, ...$connection, 'database' => $remote]);
    }

    /** Build native application fixtures after provider registration has completed. */
    protected function setUp(): void
    {
        parent::setUp();
        $this->startAdoptedSchema($this->mayReuseAdoptedSchema());
        if (! $this->hasRetainedOwnershipSchema()) {
            $this->createOwnershipFixtureTables();
        }

        Company::query()->create(['id' => 7]);
        Company::query()->create(['id' => 8]);
        Actor::query()->create(['id' => 1, 'name' => 'Operator']);
    }

    /** Opt in only for tests with a single-source adoption fixture and a known row baseline. */
    protected function mayReuseAdoptedSchema(): bool
    {
        return false;
    }

    protected function installResourceScenario(bool $queue = false): void
    {
        $reused = $this->hasRetainedOwnershipSchema();
        ResourceScenario::install($this->app, $reused);
        if ($queue) {
            QueueScenario::install($reused);
        }
        $this->retainAdoptedOwnershipSchema(ResourceScenario::SeedTables);
    }

    protected function installColumnScenario(bool $withPivot = false, bool $queue = false): void
    {
        $reused = $this->hasRetainedOwnershipSchema();
        if ($withPivot) {
            ResourceScenario::installColumnAndPivot($this->app, $reused);
        } else {
            ResourceScenario::installColumn($this->app, $reused);
        }
        if ($queue) {
            QueueScenario::install($reused);
        }
        $this->retainAdoptedOwnershipSchema($withPivot ? ResourceScenario::ColumnAndPivotSeedTables : ResourceScenario::ColumnSeedTables);
    }

    /** @param list<string> $resources */
    protected function installNativeRootScenario(array $resources): void
    {
        $seedTables = ResourceScenario::installNativeRoots($this->app, $resources, $this->hasRetainedOwnershipSchema());
        $this->retainAdoptedOwnershipSchema($seedTables);
    }

    private function createOwnershipFixtureTables(): void
    {
        $source = DB::connection();
        $schema = $source->getSchemaBuilder();
        $native = getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql';
        if ($native) {
            $this->assertSame(getenv('NVL_OWNERSHIP_TEST_DRIVER'), $source->getDriverName());
            $schema->dropAllTables();
            DB::connection('ownership_remote')->getSchemaBuilder()->dropAllTables();
        }
        $schema->create('ownership_test_companies', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->boolean('active')->default(true);
        });
        $schema->create('ownership_test_actors', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });
    }

    /**
     * Bind a host admission policy for the exact native owners.
     *
     * @param  list<int>  $companies
     */
    protected function allowOwners(array $companies = [7, 8]): void
    {
        $this->app->bind(OwnerAccess::class, static fn (): OwnerAccess => new class($companies) implements OwnerAccess
        {
            /** @param list<int> $companies */
            public function __construct(private readonly array $companies) {}

            /** Admit only the fixture actor to explicitly allowed companies. */
            public function assertCanEnter(Authenticatable $actor, Model $owner): void
            {
                if ($actor->getAuthIdentifier() !== 1 || ! in_array($owner->getKey(), $this->companies, true)) {
                    throw new OwnershipViolation;
                }
            }
        });
        $this->app->bind(OwnerAvailability::class, static fn (): OwnerAvailability => new class implements OwnerAvailability
        {
            /** Check freshly persisted host status rather than the supplied model. */
            public function assertAvailable(Model $owner): void
            {
                if ($owner->getAttribute('active') !== true) {
                    throw new OwnershipViolation;
                }
            }
        });
    }
}
