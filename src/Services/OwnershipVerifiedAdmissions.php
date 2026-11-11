<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Connection;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use WeakMap;
use WeakReference;

/**
 * Remembers what one admitted snapshot was already verified against.
 *
 * Bound as a scoped service, so nothing outlives the framework request or job. Verification is
 * repeated when the context snapshot or a connection object changes, and after every
 * coordinated adoption mutation.
 *
 * @internal
 */
final class OwnershipVerifiedAdmissions
{
    /** @var WeakMap<OwnershipSnapshot, array<string, WeakReference<Connection>>> */
    private WeakMap $resources;

    /** @var WeakMap<OwnershipSnapshot, WeakMap<Connection, string>> */
    private WeakMap $owners;

    public function __construct()
    {
        $this->forget();
    }

    /** Report whether this snapshot already verified the resource on this exact connection. */
    public function has(OwnershipSnapshot $snapshot, string $resource, Connection $connection): bool
    {
        return (($this->resources[$snapshot] ?? [])[$resource] ?? null)?->get() === $connection;
    }

    /** Record a completed installation, schema and owner verification. */
    public function remember(OwnershipSnapshot $snapshot, string $resource, Connection $connection): void
    {
        $resources = $this->resources[$snapshot] ?? [];
        $resources[$resource] = WeakReference::create($connection);
        $this->resources[$snapshot] = $resources;
    }

    /** Return the owner class verified for this snapshot on this connection; an empty string means central. */
    public function owner(OwnershipSnapshot $snapshot, Connection $connection): ?string
    {
        return ($this->owners[$snapshot] ?? null)[$connection] ?? null;
    }

    /** Record that the snapshot's generation and owner were verified through this connection. */
    public function rememberOwner(OwnershipSnapshot $snapshot, Connection $connection, string $owner): void
    {
        /** @var WeakMap<Connection, string> $owners */
        $owners = $this->owners[$snapshot] ?? new WeakMap;
        $owners[$connection] = $owner;
        $this->owners[$snapshot] = $owners;
    }

    /** Drop every remembered verification. */
    public function forget(): void
    {
        $this->resources = new WeakMap;
        $this->owners = new WeakMap;
    }
}
