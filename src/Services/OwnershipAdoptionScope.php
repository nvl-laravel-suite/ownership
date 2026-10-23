<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Support\Ownership\Contracts\OwnershipMaintenanceState;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Throwable;

/**
 * Fences one synchronous registered adoption callback without granting ordinary tenant access.
 *
 * @internal Only the adoption coordinator enters this scope; it grants no boundary bypass.
 */
final class OwnershipAdoptionScope implements OwnershipMaintenanceState
{
    /** @var array{run: string, adapter: class-string<OwnershipAdoptionAdapter>|null, phase: string}|null */
    private ?array $invocation = null;

    /** Resolve the current host dispatcher at each callback boundary. */
    public function __construct(private readonly Container $container, private readonly OwnershipSynchronousCallbacks $synchronous) {}

    /** Report whether the current application scope owns an adoption callback. */
    public function active(): bool
    {
        return $this->invocation !== null;
    }

    /** Require the exact registered adapter, persisted run and coordinator phase. */
    public function assertAdapterInvocation(string $run, string $adapter, string $phase): void
    {
        if ($this->invocation !== compact('run', 'adapter', 'phase')) {
            throw new OwnershipViolation('Host adoption helpers require their registered coordinator phase.');
        }
    }

    /** Allow schema helpers only in the coordinator's explicit nontransactional DDL phases. */
    public function assertSchemaAllowed(): void
    {
        if ($this->invocation === null || ! in_array($this->invocation['phase'], ['prepare', 'activate'], true)) {
            throw new OwnershipViolation('Ownership schema changes require a coordinated prepare or activate phase.');
        }
    }

    /** Reject publication before any queue payload or deferred callback is retained. */
    public function assertQueueAllowed(): void
    {
        if ($this->active()) {
            throw new OwnershipViolation('Queue dispatch and deferred work are forbidden during adoption callbacks.');
        }
    }

    /**
     * Enter one immutable run/adapter/phase reference and restore host behavior in finally.
     *
     * @template T
     *
     * @param  class-string<OwnershipAdoptionAdapter>  $adapter
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(string $run, string $adapter, string $phase, Closure $callback): mixed
    {
        $previous = $this->invocation;
        $insidePreparation = $previous !== null && $previous['phase'] === 'preparation'
            && $previous['run'] === $run && $phase === 'metadata';
        if ($previous !== null && ! $insidePreparation) {
            throw new OwnershipViolation('An adoption callback is already active.');
        }
        $this->invocation = compact('run', 'adapter', 'phase');
        try {
            return $this->synchronous->run($this->container->make(Dispatcher::class), fn () => $this->balanced($callback, $insidePreparation));
        } finally {
            $this->invocation = $previous;
        }
    }

    /**
     * Fence the coordinator-owned ingestion transaction through its commit callback release.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function preparation(string $run, Closure $callback): mixed
    {
        if ($this->active()) {
            throw new OwnershipViolation('An adoption callback is already active.');
        }
        $this->invocation = ['run' => $run, 'adapter' => null, 'phase' => 'preparation'];
        try {
            return $this->synchronous->run($this->container->make(Dispatcher::class), fn () => $this->balanced($callback));
        } finally {
            $this->invocation = null;
        }
    }

    /**
     * Revoke escaped after-commit callbacks while the publication fence remains active.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    private function balanced(Closure $callback, bool $insidePreparation = false): mixed
    {
        $connections = $this->container->make(OwnershipConnections::class);
        $initial = $connections->participating();
        $levels = [];
        foreach ($initial as $id => $connection) {
            $levels[$id] = $insidePreparation && $connection === $connections->core() ? 1 : 0;
            if ($connection->transactionLevel() !== $levels[$id]) {
                throw new OwnershipViolation('Adoption callbacks require balanced participating transactions.');
            }
        }
        $failure = null;
        try {
            return $callback();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            $cleanup = [];
            foreach ($initial + $connections->participating() as $id => $connection) {
                $level = $levels[$id] ?? 0;
                if ($connection->transactionLevel() === $level) {
                    continue;
                }
                $failure ??= new OwnershipViolation('An adoption callback changed its transaction balance.');
                try {
                    if ($connection->transactionLevel() > $level) {
                        $connection->rollBack($level);
                    }
                } catch (Throwable $exception) {
                    $cleanup[] = $exception;
                }
            }
            try {
                foreach ($cleanup as $exception) {
                    $this->container->make(ExceptionHandler::class)->report($exception);
                }
            } finally {
                if ($failure !== null) {
                    throw $failure;
                }
            }
        }
    }
}
