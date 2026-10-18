<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\Contracts\OwnerAccess;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Contracts\OwnershipRunner as RunnerContract;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

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
        ), $operation);
    }

    /**
     * Deny system work until bounded admission can be durably recorded.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function runSystem(OwnerReference $owner, string $operation, Closure $callback): mixed
    {
        $this->container->make(OwnershipConfiguration::class)->assertReady();

        throw new OwnershipViolation('System ownership execution requires durable operation admission.');
    }

    /**
     * Deny central work until its capability can be durably recorded.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function central(string $operation, Authenticatable $actor, Closure $callback): mixed
    {
        $this->container->make(OwnershipConfiguration::class)->assertReady();

        throw new OwnershipViolation('Central ownership execution requires durable operation admission.');
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
        $context->replace($snapshot);
        try {
            return $callback();
        } finally {
            $context->replace($previous);
        }
    }
}
