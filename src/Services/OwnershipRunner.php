<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\CentralAccess;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Contracts\OwnershipRunner as RunnerContract;
use Nvl\Ownership\Contracts\OwnerSystemAccess;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Contracts\OwnershipContextParticipant;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipContextParticipants;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext;
use Throwable;

/** Admits work and restores state without retaining an old request's adapters. */
final readonly class OwnershipRunner implements RunnerContract
{
    /** Resolve lifecycle-scoped adapters at the framework execution boundary. */
    public function __construct(private Container $container) {}

    /**
     * Execute after fresh canonical owner and host policy checks.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Model $owner, Authenticatable $actor, Closure $operation): mixed
    {
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        $this->container->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
        $identities = $this->container->make(OwnerIdentityResolver::class);
        $reference = $identities->reference($owner);
        $canonical = $identities->resolve($reference);
        $this->container->make(OwnerAvailability::class)->assertAvailable($canonical);
        $this->container->make(OwnerAccess::class)->assertCanEnter($actor, $canonical);
        $id = $actor->getAuthIdentifier();
        if (! is_string($id) && ! is_int($id)) {
            throw new OwnershipViolation;
        }

        return $this->within(new OwnershipSnapshot(
            OwnershipContextMode::Owner,
            $reference,
            ['kind' => 'actor', 'type' => $actor::class, 'id' => (string) $id],
            generations: $this->container->make(OwnershipInstallationState::class)->generations(),
        ), $operation);
    }

    /**
     * Admit allowlisted system work after fresh policy checks and durable audit.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function runSystem(OwnerReference $owner, string $operation, Closure $callback): mixed
    {
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        $this->container->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
        $this->assertOperation('system_operations', $operation);
        $canonical = $this->container->make(OwnerIdentityResolver::class)->resolve($owner);
        $this->container->make(OwnerAvailability::class)->assertAvailable($canonical);
        $this->container->make(OwnerSystemAccess::class)->assertCanRun($operation, $canonical);
        $generations = $this->container->make(OwnershipInstallationState::class)->generations();
        $this->container->make(OwnershipOperationRecorder::class)->record(new OwnershipOperation($operation, 'system', $operation), $owner);

        return $this->within(new OwnershipSnapshot(OwnershipContextMode::Owner, $owner, ['kind' => 'system', 'type' => 'operation', 'id' => $operation], $operation, $generations), $callback);
    }

    /**
     * Admit central capabilities after explicit host policy and durable audit.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function central(string $operation, Authenticatable $actor, Closure $callback): mixed
    {
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        $this->container->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
        $this->assertOperation('central_operations', $operation);
        $this->container->make(CentralAccess::class)->assertCanRun($operation, $actor);
        $id = $actor->getAuthIdentifier();
        if (! is_int($id) && ! is_string($id)) {
            throw new OwnershipViolation;
        }
        $generations = $this->container->make(OwnershipInstallationState::class)->generations();
        $this->container->make(OwnershipOperationRecorder::class)->record(new OwnershipOperation($operation, $actor::class, (string) $id));

        return $this->within(new OwnershipSnapshot(OwnershipContextMode::Central, principal: ['kind' => 'actor', 'type' => $actor::class, 'id' => (string) $id], operation: $operation, generations: $generations), $callback);
    }

    /**
     * Install public admission after the boundary resolver verifies canonical owner and origin.
     *
     * @internal
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function publicSite(VerifiedOwnerSiteContext $site, Closure $callback): mixed
    {
        $this->container->make(OwnershipAdoptionScope::class)->assertQueueAllowed();

        return $this->within(new OwnershipSnapshot(
            OwnershipContextMode::Owner,
            $site->owner,
            ['kind' => 'public', 'type' => 'site', 'id' => $site->site],
            generations: $this->container->make(OwnershipInstallationState::class)->generations(),
            publicSite: $site,
        ), $callback);
    }

    /** Require an explicit deployment allowlist independently of host authorization. */
    private function assertOperation(string $key, string $operation): void
    {
        $operations = $this->container->make(Repository::class)->get('nvl-ownership.'.$key);
        if (! is_array($operations) || ! in_array($operation, $operations, true)) {
            throw new OwnershipViolation('The operation is not allowlisted.');
        }
    }

    /**
     * Restore the previous scope even when the callback fails.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    private function within(OwnershipSnapshot $snapshot, Closure $callback): mixed
    {
        $context = $this->container->make(ScopedOwnershipContext::class);
        $previous = $context->snapshot();
        if ($previous->publicSite !== null && $snapshot !== $previous) {
            throw new OwnershipViolation('Public admission cannot switch owner, site or authority.');
        }
        $connections = $this->container->make(OwnershipConnections::class);
        $levels = [];
        foreach ($connections->participating() as $id => $connection) {
            $levels[$id] = $connection->transactionLevel();
            if ($levels[$id] > 0 && ($previous->mode !== $snapshot->mode || $previous->owner != $snapshot->owner || $previous->generations !== $snapshot->generations)) {
                throw new OwnershipViolation('Ownership context cannot change inside a participating transaction.');
            }
        }
        $restorers = [];
        $failure = null;
        $cleanupFailures = [];
        $context->replace($snapshot);
        try {
            foreach ($this->container->make(OwnershipContextParticipants::class)->all() as $participant) {
                $restorers[] = $this->enterParticipant($this->container->make($participant), $snapshot);
            }

            return $callback();
        } catch (Throwable $exception) {
            $failure = $exception;
            throw $exception;
        } finally {
            foreach (array_reverse($restorers) as $restore) {
                try {
                    $restore();
                } catch (Throwable $exception) {
                    $this->cleanupFailure($context, $exception, $failure, $cleanupFailures);
                }
            }
            foreach ($connections->participating() as $id => $connection) {
                $initial = $levels[$id] ?? 0;
                if ($connection->transactionLevel() !== $initial) {
                    $exception = new OwnershipViolation('Ownership callback changed its caller transaction balance.');
                    try {
                        if ($connection->transactionLevel() > $initial) {
                            $connection->rollBack($initial);
                        }
                    } catch (Throwable $rollbackFailure) {
                        $this->cleanupFailure($context, $rollbackFailure, $failure, $cleanupFailures);
                    }
                    $this->cleanupFailure($context, $exception, $failure, $cleanupFailures);
                }
            }
            if (! $context->invalid()) {
                $context->replace($previous);
            }
            try {
                foreach ($cleanupFailures as $cleanupFailure) {
                    $this->container->make(ExceptionHandler::class)->report($cleanupFailure);
                }
            } finally {
                if ($failure !== null) {
                    throw $failure;
                }
            }
        }
    }

    /**
     * Enter a resolved participant through its declared contract.
     *
     * @return Closure(): void
     */
    private function enterParticipant(mixed $participant, OwnershipSnapshot $next): Closure
    {
        if (! $participant instanceof OwnershipContextParticipant) {
            throw new OwnershipConfigurationInvalid('A registered participant binding must implement OwnershipContextParticipant.');
        }

        return $participant->enter($next);
    }

    /**
     * Collect cleanup corruption for reporting after restoration and preserve the original failure.
     *
     * @param  list<Throwable>  $cleanupFailures
     *
     * @param-out Throwable $failure
     */
    private function cleanupFailure(ScopedOwnershipContext $context, Throwable $exception, ?Throwable &$failure, array &$cleanupFailures): void
    {
        $context->invalidate();
        $failure ??= $exception;
        $cleanupFailures[] = $exception;
    }
}
