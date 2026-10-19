<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Nvl\Ownership\Contracts\OwnershipActorResolver;
use Nvl\Ownership\Queue\OwnershipDatabaseBatchRepository;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCarrier;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Nvl\Support\Tenancy\Services\PersistedTenantStorage;

/** Re-admits scalar capture against fresh worker policies, actors, owners, and generations. @internal */
final readonly class OwnershipQueueContext implements \Nvl\Support\Ownership\Contracts\OwnershipQueueContext
{
    /** Retain only the current application, never a captured request adapter. */
    public function __construct(private Container $container) {}

    /** Capture explicit global execution without deriving any owner or central authority. */
    public function captureUnowned(): OwnershipJobEnvelope
    {
        $this->container->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        if ($this->container->make(OwnershipContext::class)->snapshot()->publicSite !== null) {
            throw new OwnershipViolation('Public site admission cannot schedule privileged or global package work.');
        }

        return OwnershipJobEnvelope::unowned($this->container->make(OwnershipInstallationState::class)->generations());
    }

    /** Validate every job before persisting native batch callbacks. */
    public function captureBatch(PendingBatch $batch): PendingBatch
    {
        if (! $this->container->make(BatchRepository::class) instanceof OwnershipDatabaseBatchRepository) {
            throw new OwnershipViolation('Owned batches require a guarded native database repository.');
        }
        $first = $batch->jobs->flatten()->first();
        $firstEnvelope = is_object($first) ? $this->container->make(OwnershipQueueCarrier::class)->envelope($first) : null;
        $unowned = $firstEnvelope?->context()->mode === OwnershipContextMode::Unresolved;
        if ($unowned && $this->container->make(OwnershipContext::class)->snapshot()->mode !== OwnershipContextMode::Unresolved) {
            throw new OwnershipViolation('Explicit global batches must be scheduled without owner or central authority.');
        }
        $envelope = $unowned ? $this->captureUnowned() : OwnershipJobEnvelope::capture($this->container->make(OwnershipContext::class));
        $this->run($envelope, static fn (): null => null);
        foreach ($batch->jobs->flatten() as $job) {
            if (! is_object($job) || $this->container->make(OwnershipQueueCarrier::class)->envelope($job)->toArray() !== $envelope->toArray()) {
                throw new OwnershipViolation('A batch cannot mix captured ownership boundaries.');
            }
        }

        return $batch->withOption('nvl_ownership', $envelope->toArray());
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    public function run(OwnershipJobEnvelope $envelope, Closure $operation): mixed
    {
        $this->container->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
        $envelope = OwnershipJobEnvelope::fromArray($envelope->toArray());
        $snapshot = $envelope->context();
        if ($snapshot->mode === OwnershipContextMode::Disabled) {
            foreach ($this->container->make(OwnershipResourceRegistry::class)->all() as $resource) {
                if ($resource->ownership->strategy !== 'unscoped') {
                    throw new OwnershipViolation('Disabled work cannot enter an owned deployment.');
                }
            }
            $this->container->make(PersistedTenantStorage::class)->assertQueueUsable();
            $context = $this->container->make(ScopedOwnershipContext::class);
            $previous = $context->snapshot();
            $context->replace(new OwnershipSnapshot(OwnershipContextMode::Disabled));
            try {
                return $operation();
            } finally {
                $context->replace($previous);
            }
        }
        if ($this->container->make('config')->get('nvl-ownership.enabled') !== true) {
            throw new OwnershipViolation('Captured ownership requires an enabled enforcing worker.');
        }
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        $this->container->make(OwnershipInstallationState::class)->assertSnapshot($snapshot);
        $runner = $this->container->make(OwnershipRunner::class);
        if ($snapshot->mode === OwnershipContextMode::Unresolved) {
            return $runner->unowned($operation);
        }
        $principal = $snapshot->principal ?? throw new OwnershipViolation;
        if ($principal['kind'] === 'system') {
            return $runner->runSystem($snapshot->owner ?? throw new OwnershipViolation, $snapshot->operation ?? throw new OwnershipViolation, $operation);
        }
        $actor = $this->container->make(OwnershipActorResolver::class)->resolve($principal['type'], $principal['id']);
        if ($snapshot->mode === OwnershipContextMode::Central) {
            return $runner->central($snapshot->operation ?? throw new OwnershipViolation, $actor, $operation);
        }
        $owner = $this->container->make(OwnerIdentityResolver::class)->resolve($snapshot->owner ?? throw new OwnershipViolation);

        return $runner->run($owner, $actor, $operation);
    }
}
