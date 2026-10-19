<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\TestCase;

/** Boots only the neutral Core and optional Ownership runtime against native host keys. */
abstract class OwnershipTestCase extends TestCase
{
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
        if (! in_array($driver, ['pgsql', 'mysql', 'mariadb'], true)) {
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
        if ($driver === 'pgsql') {
            $connection = ['driver' => 'pgsql', 'host' => '/tmp', 'port' => '5432', 'database' => $primary, 'username' => get_current_user(), 'password' => '', 'charset' => 'utf8', 'prefix' => '', 'search_path' => 'public', 'sslmode' => 'prefer', 'timezone' => 'UTC'];
        } else {
            $host = getenv('NVL_OWNERSHIP_TEST_HOST') ?: '127.0.0.1';
            $port = getenv('NVL_OWNERSHIP_TEST_PORT');
            if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || ! is_string($port) || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
                throw new \RuntimeException('MySQL/MariaDB persistence tests require an explicit loopback host and test port.');
            }
            $connection = ['driver' => $driver, 'host' => $host, 'port' => $port, 'database' => $primary, 'username' => getenv('NVL_OWNERSHIP_TEST_USERNAME') ?: 'root', 'password' => getenv('NVL_OWNERSHIP_TEST_PASSWORD') ?: '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_bin', 'prefix' => '', 'prefix_indexes' => true, 'strict' => true, 'engine' => 'InnoDB', 'timezone' => '+00:00'];
        }
        $app['config']->set('database.connections.sqlite', ['url' => null, ...$connection]);
        $app['config']->set('database.connections.ownership_remote', ['url' => null, ...$connection, 'database' => $remote]);
    }

    /** Build native application fixtures after provider registration has completed. */
    protected function setUp(): void
    {
        parent::setUp();
        if (in_array(getenv('NVL_OWNERSHIP_TEST_DRIVER'), ['pgsql', 'mysql', 'mariadb'], true)) {
            foreach (['sqlite', 'ownership_remote'] as $connection) {
                DB::connection($connection)->getSchemaBuilder()->dropAllTables();
            }
        }
        Schema::create('ownership_test_companies', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->boolean('active')->default(true);
        });
        Schema::create('ownership_test_actors', static function (Blueprint $table): void {
            $table->unsignedBigInteger('id')->primary();
            $table->string('name');
        });
        Company::query()->create(['id' => 7]);
        Company::query()->create(['id' => 8]);
        Actor::query()->create(['id' => 1, 'name' => 'Operator']);
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
