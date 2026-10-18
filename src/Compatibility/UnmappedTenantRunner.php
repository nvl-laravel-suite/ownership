<?php

declare(strict_types=1);

namespace Nvl\Ownership\Compatibility;

use Closure;
use Illuminate\Container\Container;
use Nvl\Ownership\Services\OwnershipConnections;
use Nvl\Ownership\Services\OwnershipInstallationState;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Rejects legacy entry until a verified ownership mapping is admitted. */
final readonly class UnmappedTenantRunner implements TenantRunner
{
    /** Retain the live scalar context and verified adoption stores. */
    public function __construct(private Container $container, private OwnershipConnections $connections, private OwnershipInstallationState $installation) {}

    /**
     * Reject an identity without verified ownership admission.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(TenantId $tenant, Closure $operation): mixed
    {
        $context = $this->container->make(OwnershipContext::class);
        $owner = $context->requireOwner();
        $snapshot = $context->snapshot();
        $this->installation->assertSnapshot($snapshot);
        $found = false;
        foreach ($this->connections->core()->table(Tables::Mappings.' as mappings')
            ->join(Tables::Runs.' as runs', 'runs.id', '=', 'mappings.run_id')
            ->where('mappings.legacy_tenant_id', $tenant->value)->where('runs.status', 'active')
            ->select(['mappings.owner_reference', 'mappings.resource', 'mappings.run_id'])->useWritePdo()->cursor() as $mapping) {
            $reference = is_string($mapping->owner_reference) ? json_decode($mapping->owner_reference, true, 32, JSON_THROW_ON_ERROR) : null;
            if (! is_string($mapping->owner_reference) || ! is_string($mapping->resource) || ! is_string($mapping->run_id)
                || ! is_array($reference)
                || ! isset($snapshot->generations[$mapping->resource])
                || ! str_ends_with($snapshot->generations[$mapping->resource], ':'.$mapping->run_id)
                || OwnerReference::fromArray($reference) != $owner) {
                throw new OwnershipViolation('The legacy identity has no single matching admitted owner.');
            }
            $found = true;
        }
        if (! $found) {
            throw new OwnershipViolation('The legacy identity has no durable active mapping.');
        }

        return $operation();
    }

    /**
     * Reject implicit legacy central authority.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function platform(PlatformOperation $operation, Closure $callback): mixed
    {
        throw new OwnershipViolation;
    }

    /**
     * Reject legacy context as an ownership escape.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function withoutTenant(TenantContextSnapshot $snapshot, Closure $operation): mixed
    {
        throw new OwnershipViolation;
    }
}
