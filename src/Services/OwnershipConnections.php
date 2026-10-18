<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Str;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\OwnerRegistry;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;

/** Resolves actual source sessions and durable store identities. @internal */
final readonly class OwnershipConnections
{
    /** Retain connection services and immutable declarations. */
    public function __construct(private DatabaseManager $database, private OwnershipResourceRegistry $resources, private OwnerRegistry $owners) {}

    /** Return the configured coordinator connection. */
    public function core(): Connection
    {
        return $this->database->connection(PackageStorage::connection('ownership'));
    }

    /** Return the exact canonical resource connection. */
    public function resource(string $resource): Connection
    {
        $definition = $this->resources->get($resource);

        return (new $definition->model)->getConnection();
    }

    /** Return the effective named connection. */
    public function name(?string $name): string
    {
        return $this->database->connection($name)->getName() ?? throw new OwnershipSchemaNotReady;
    }

    /** Require a preinstalled native store identity without creating one during admission. */
    public function identity(Connection $connection): string
    {
        if (! $connection->getSchemaBuilder()->hasTable(Tables::Stores)) {
            throw new OwnershipSchemaNotReady('Install the ownership protocol on every participating connection.');
        }
        $identity = $connection->table(Tables::Stores)->useWritePdo()->where('id', 1)->value('store_id');
        if (! is_string($identity) || ! Str::isUuid($identity)) {
            throw new OwnershipSchemaNotReady('The native ownership store identity is invalid.');
        }

        return $identity;
    }

    /** @return array<int, Connection> */
    public function participating(): array
    {
        $this->core();
        foreach ($this->resources->all() as $definition) {
            $this->resource($definition->key);
        }
        $connections = [];
        foreach ($this->database->getConnections() as $connection) {
            $connections[spl_object_id($connection)] = $connection;
        }

        return $connections;
    }

    /** @return array<int, Connection> */
    public function sources(): array
    {
        $core = $this->core();
        $sources = [spl_object_id($core) => $core];
        foreach ($this->resources->all() as $definition) {
            if ($definition->ownership->strategy === 'unscoped') {
                continue;
            }
            $source = $this->resource($definition->key);
            $sources[spl_object_id($source)] = $source;
            $ownerClass = $definition->ownership->owner;
            if ($ownerClass !== null) {
                $ownerClass = $this->owners->model($ownerClass);
                $owner = (new $ownerClass)->getConnection();
                $sources[spl_object_id($owner)] = $owner;
            }
        }

        return $sources;
    }
}
