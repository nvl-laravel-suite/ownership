<?php

declare(strict_types=1);

namespace Nvl\Ownership\Compatibility;

use Closure;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Tenancy\Contracts\TenantRunner;
use Nvl\Support\Tenancy\ValueObjects\PlatformOperation;
use Nvl\Support\Tenancy\ValueObjects\TenantContextSnapshot;
use Nvl\Support\Tenancy\ValueObjects\TenantId;

/** Rejects legacy entry until a verified ownership mapping is admitted. */
final readonly class UnmappedTenantRunner implements TenantRunner
{
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
        throw new OwnershipViolation('Legacy tenant entry requires an explicit admitted ownership mapping.');
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
