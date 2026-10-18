<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
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
    }

    /** Build native application fixtures after provider registration has completed. */
    protected function setUp(): void
    {
        parent::setUp();
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
