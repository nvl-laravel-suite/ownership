<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use Illuminate\Database\Connection;
use PDO;
use RuntimeException;

/** Validates the actual native source used by independent persistence fixtures. */
final class NativeDatabaseSource
{
    /** @return array<string, mixed> */
    public static function capture(Connection $connection): array
    {
        $configuration = $connection->getConfig();
        if (! is_array($configuration)) {
            throw new RuntimeException('A native worker requires an explicit connection configuration.');
        }
        $source = [];
        foreach ($configuration as $key => $value) {
            if (! is_string($key)) {
                throw new RuntimeException('Native connection configuration keys must be strings.');
            }
            $source[$key] = $value;
        }
        $source['_nvl_native_parent'] = self::inspect($connection);

        return $source;
    }

    /**
     * @param  array<string, mixed>|null  $parent
     * @return array{driver: string, pdo_driver: string, server_version: string, database: string, process_id: int, connection_id: int}
     */
    public static function inspect(Connection $connection, ?array $parent = null): array
    {
        $driver = $connection->getDriverName();
        $database = $connection->getConfig('database');
        if ($driver !== 'pgsql'
            || ! is_string($database)
            || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $database) !== 1
            || $connection->transactionLevel() !== 0) {
            throw new RuntimeException('A native worker requires a dedicated committed persistence source.');
        }

        $connection->reconnectIfMissingConnection();
        $pdoDriver = $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        $serverVersion = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
        $processId = getmypid();
        $connectionId = filter_var($connection->scalar('SELECT pg_backend_pid()'), FILTER_VALIDATE_INT);
        if ($pdoDriver !== $driver
            || ! is_string($serverVersion) || $serverVersion === ''
            || ! is_int($processId) || $processId < 1
            || ! is_int($connectionId) || $connectionId < 1) {
            throw new RuntimeException('A native worker selected an unexpected ORM, PDO, or physical database source.');
        }

        $identity = ['driver' => $driver, 'pdo_driver' => $pdoDriver, 'server_version' => $serverVersion, 'database' => $database, 'process_id' => $processId, 'connection_id' => $connectionId];
        $parent ??= $connection->getConfig('_nvl_native_parent');
        if ($parent !== null
            && (! is_array($parent)
                || array_intersect_key($identity, array_flip(['driver', 'pdo_driver', 'server_version', 'database'])) !== array_intersect_key($parent, array_flip(['driver', 'pdo_driver', 'server_version', 'database']))
                || $processId === ($parent['process_id'] ?? null)
                || $connectionId === ($parent['connection_id'] ?? null))) {
            throw new RuntimeException('A native worker must use the same physical source through an independent process and server connection.');
        }

        return $identity;
    }
}
