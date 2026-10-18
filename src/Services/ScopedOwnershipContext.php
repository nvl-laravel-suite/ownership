<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Holds mutable ownership only within one framework request or job lifecycle. */
final class ScopedOwnershipContext implements OwnershipContext
{
    private OwnershipSnapshot $current;

    private bool $invalid = false;

    /** Initialize with no inferred owner or principal. */
    public function __construct(Repository $configuration)
    {
        $this->current = new OwnershipSnapshot($configuration->get('nvl-ownership.enabled') === true
            ? OwnershipContextMode::Unresolved : OwnershipContextMode::Disabled);
    }

    /** Return immutable current execution facts. */
    public function snapshot(): OwnershipSnapshot
    {
        return $this->current;
    }

    /** Require one current owner and a non-revoked scope. */
    public function requireOwner(): OwnerReference
    {
        if ($this->invalid || $this->current->owner === null) {
            throw new OwnershipViolation;
        }

        return $this->current->owner;
    }

    /** @internal Replace state through authorized execution boundaries only. */
    public function replace(OwnershipSnapshot $next): void
    {
        if ($this->invalid) {
            throw new OwnershipViolation('Ownership scope cleanup invalidated execution.');
        }
        $this->current = $next;
    }

    /** @internal Revoke a scope after failed cleanup. */
    public function invalidate(): void
    {
        $this->invalid = true;
        $this->current = new OwnershipSnapshot(OwnershipContextMode::Unresolved);
    }

    /** @internal Report whether failed cleanup revoked the current lifecycle. */
    public function invalid(): bool
    {
        return $this->invalid;
    }
}
