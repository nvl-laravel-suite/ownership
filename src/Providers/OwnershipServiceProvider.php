<?php

declare(strict_types=1);

namespace Nvl\Ownership\Providers;

use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\ServiceProvider;
use Nvl\Ownership\Compatibility\UnmappedTenantRunner;
use Nvl\Ownership\Console\Commands\OwnershipDoctorCommand;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Contracts\OwnershipRunner as RunnerContract;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Services\DenyCentralAccess;
use Nvl\Ownership\Services\DenyOwnerAccess;
use Nvl\Ownership\Services\DenyOwnershipAdoptionAccess;
use Nvl\Ownership\Services\DenyOwnerSystemAccess;
use Nvl\Ownership\Services\ExistingOwnerAvailability;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipAdoptionScope;
use Nvl\Ownership\Services\OwnershipConfiguration;
use Nvl\Ownership\Services\OwnershipDoctor;
use Nvl\Ownership\Services\OwnershipMaintenanceQueueGuard;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\ScopedOwnershipContext;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipMaintenanceState;
use Nvl\Support\Ownership\Services\DisabledOwnershipContext;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Providers\SupportServiceProvider;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Traits\MergesPackageConfiguration;

/** Registers the optional runtime without installing owner or tenant schema. */
final class OwnershipServiceProvider extends ServiceProvider
{
    use MergesPackageConfiguration;

    /** Register immutable metadata and fresh execution services. */
    public function register(): void
    {
        $this->app->register(SupportServiceProvider::class);
        $this->mergePackageConfiguration(__DIR__.'/../../config/nvl-ownership.php', 'ownership');
        $this->app->instance('nvl.ownership.runtime', true);
        $this->app->singletonIf(OwnershipResourceRegistry::class);
        $this->app->singletonIf(OwnershipAdoptionRegistry::class);
        $this->app->scopedIf(OwnershipAdoptionScope::class);
        $this->app->scoped(OwnershipMaintenanceState::class, static fn (Application $app): OwnershipMaintenanceState => $app->make(OwnershipAdoptionScope::class));
        $this->app->bindIf(OwnershipAdoptionAccess::class, DenyOwnershipAdoptionAccess::class);
        foreach ([QueueFactory::class, DeferredCallbackCollection::class] as $boundary) {
            $this->app->beforeResolving($boundary, static function (): void {
                $app = Container::getInstance();
                if ($app->bound(OwnershipAdoptionScope::class)) {
                    $app->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
                }
            });
        }
        OwnershipMaintenanceQueueGuard::register();
        $this->app->scopedIf(ScopedOwnershipContext::class);
        $this->app->extend(OwnershipContext::class, static fn (OwnershipContext $context, Application $app): OwnershipContext => $context instanceof DisabledOwnershipContext
            ? $app->make(ScopedOwnershipContext::class) : $context);
        $this->app->bindIf(OwnerAccess::class, DenyOwnerAccess::class);
        $this->app->bindIf(OwnerSystemAccess::class, DenyOwnerSystemAccess::class);
        $this->app->bindIf(CentralAccess::class, DenyCentralAccess::class);
        $this->app->bindIf(OwnerAvailability::class, ExistingOwnerAvailability::class);
        $this->app->bindIf(RunnerContract::class, OwnershipRunner::class);
        $this->app->extend(TenantRunner::class, static fn (TenantRunner $runner, Application $app): TenantRunner => $app->make(Repository::class)->get('nvl-ownership.enabled') === true
            ? $app->make(UnmappedTenantRunner::class) : $runner);
    }

    /** Validate deployment options without actors or database connections. */
    public function boot(OwnershipConfiguration $configuration): void
    {
        $configuration->validate();
        if ($this->app->runningInConsole()) {
            $this->commands([OwnershipDoctorCommand::class]);
        }
        PackageDoctorContributor::register($this->app, 'nvl/ownership', fn (): array => $this->app->make(OwnershipDoctor::class)->inspect());
        $this->publishes([__DIR__.'/../../config/nvl-ownership.php' => config_path('nvl-ownership.php')], 'nvl-ownership-config');
        $path = __DIR__.'/../../database/migrations/ownership';
        $this->publishesMigrations([$path => database_path('migrations')], 'nvl-ownership-migrations');
        if ($this->app->make(Repository::class)->get('nvl-ownership.migrations.enabled') === true) {
            $this->loadMigrationsFrom($path);
        }
    }
}
