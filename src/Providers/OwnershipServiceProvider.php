<?php

declare(strict_types=1);

namespace Nvl\Ownership\Providers;

use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Events\ConnectionEstablished;
use Illuminate\Database\Events\TransactionCommitting;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Support\Defer\DeferredCallbackCollection;
use Illuminate\Support\ServiceProvider;
use Nvl\Ownership\Compatibility\UnmappedTenantRunner;
use Nvl\Ownership\Console\Commands\OwnershipDoctorCommand;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Contracts\OwnershipActorResolver;
use Nvl\Ownership\Contracts\OwnershipAdoptionAccess;
use Nvl\Ownership\Contracts\OwnershipRunner as RunnerContract;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\Observers\OwnershipModelObserver;
use Nvl\Ownership\Queue\OwnershipCallQueuedHandler;
use Nvl\Ownership\Queue\OwnershipDatabaseBatchRepository;
use Nvl\Ownership\Services\DenyCentralAccess;
use Nvl\Ownership\Services\DenyOwnerAccess;
use Nvl\Ownership\Services\DenyOwnershipAdoptionAccess;
use Nvl\Ownership\Services\DenyOwnerSystemAccess;
use Nvl\Ownership\Services\ExistingOwnerAvailability;
use Nvl\Ownership\Services\NativeOwnershipActorResolver;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Ownership\Services\OwnershipAdoptionScope;
use Nvl\Ownership\Services\OwnershipBatchOptions;
use Nvl\Ownership\Services\OwnershipBoundary;
use Nvl\Ownership\Services\OwnershipConfiguration;
use Nvl\Ownership\Services\OwnershipCreationScope;
use Nvl\Ownership\Services\OwnershipDoctor;
use Nvl\Ownership\Services\OwnershipMaintenanceQueueGuard;
use Nvl\Ownership\Services\OwnershipModelPersistence;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Ownership\Services\OwnershipReadiness;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriteLease;
use Nvl\Ownership\Services\PackageOwnershipWriter;
use Nvl\Ownership\Services\ScopedOwnershipContext;
use Nvl\Support\Doctor\PackageDoctorContributor;
use Nvl\Support\Ownership\Compatibility\LegacyOwnershipBoundary;
use Nvl\Support\Ownership\Contracts\OwnershipBatchOptions as BatchOptionsContract;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary as BoundaryContract;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipMaintenanceState;
use Nvl\Support\Ownership\Contracts\OwnershipModelGuard;
use Nvl\Support\Ownership\Contracts\OwnershipModelPersistence as PersistenceContract;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext as QueueContextContract;
use Nvl\Support\Ownership\Contracts\OwnershipWriter as PackageWriterContract;
use Nvl\Support\Ownership\Services\DisabledOwnershipBatchOptions;
use Nvl\Support\Ownership\Services\DisabledOwnershipContext;
use Nvl\Support\Ownership\Services\DisabledOwnershipQueueContext;
use Nvl\Support\Ownership\Services\LegacyOwnershipWriter;
use Nvl\Support\Ownership\Services\OwnershipBatchRepository as CoreBatchRepository;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\UnavailableOwnershipReadiness;
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
        $this->app->scopedIf(OwnershipCreationScope::class);
        $this->app->scopedIf(OwnershipWriteLease::class);
        $this->app->bind(OwnershipModelGuard::class, OwnershipModelObserver::class);
        $this->app->bind(PersistenceContract::class, OwnershipModelPersistence::class);
        $this->app->extend(\Nvl\Support\Ownership\Contracts\OwnershipReadiness::class, static fn ($readiness, Application $app) => $readiness instanceof UnavailableOwnershipReadiness ? $app->make(OwnershipReadiness::class) : $readiness);
        $this->app->extend(PackageWriterContract::class, static fn (PackageWriterContract $writer, Application $app): PackageWriterContract => $writer instanceof LegacyOwnershipWriter ? $app->make(PackageOwnershipWriter::class) : $writer);
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
        $this->app->bindIf(OwnershipActorResolver::class, NativeOwnershipActorResolver::class);
        $this->app->extend(QueueContextContract::class, static fn (QueueContextContract $context, Application $app): QueueContextContract => $context instanceof DisabledOwnershipQueueContext ? $app->make(OwnershipQueueContext::class) : $context);

        $this->app->bindIf(RunnerContract::class, OwnershipRunner::class);
        $this->app->extend(BoundaryContract::class, static fn (BoundaryContract $boundary, Application $app): BoundaryContract => $boundary instanceof LegacyOwnershipBoundary ? $app->make(OwnershipBoundary::class) : $boundary);
        $this->app->extend(TenantRunner::class, static fn (TenantRunner $runner, Application $app): TenantRunner => $app->make(Repository::class)->get('nvl-ownership.enabled') === true
            ? $app->make(UnmappedTenantRunner::class) : $runner);
    }

    /** Validate deployment options without actors or database connections. */
    public function boot(OwnershipConfiguration $configuration): void
    {
        $configuration->validate();
        if ($this->app->make(Repository::class)->get('nvl-ownership.enabled') === true) {
            $this->app->extend(BatchOptionsContract::class, static fn (BatchOptionsContract $options, Application $app): BatchOptionsContract => $options instanceof DisabledOwnershipBatchOptions ? $app->make(OwnershipBatchOptions::class) : $options);
            $this->app->bindIf(CallQueuedHandler::class, OwnershipCallQueuedHandler::class);
            $this->app->extend(DatabaseBatchRepository::class, static fn ($repository) => is_object($repository) && in_array($repository::class, [DatabaseBatchRepository::class, CoreBatchRepository::class], true) ? OwnershipDatabaseBatchRepository::fromNative($repository) : $repository);
        }
        $events = $this->app->make(Dispatcher::class);
        $events->listen(ConnectionEstablished::class, static fn (ConnectionEstablished $event) => OwnershipWriteLease::register($event->connection));
        $events->listen(TransactionCommitting::class, function (TransactionCommitting $event): void {
            $this->app->make(OwnershipWriteLease::class)->assertCommitAllowed($event->connection);
        });
        foreach ($this->app->make(DatabaseManager::class)->getConnections() as $connection) {
            OwnershipWriteLease::register($connection);
        }
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
