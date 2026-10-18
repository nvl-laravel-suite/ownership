<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Database\Connection;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use PDO;
use ReflectionProperty;
use WeakMap;

/** Serializes adoption across processes without wrapping DDL or audits in a transaction. @internal */
final class OwnershipAdoptionLock
{
    /** @var WeakMap<Connection, true>|null */
    private static ?WeakMap $held = null;

    /**
     * Hold the database/session lock through the complete phase, failing closed on contention.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(Connection $connection, Closure $callback): mixed
    {
        self::$held ??= new WeakMap;
        if (isset(self::$held[$connection]) || $connection->transactionLevel() !== 0) {
            throw new OwnershipViolation('Adoption cannot reenter or run inside an existing transaction.');
        }
        $pdo = $connection->getPdo();
        $release = $this->acquire($connection, $pdo);
        self::$held[$connection] = true;
        $reconnector = new ReflectionProperty(Connection::class, 'reconnector');
        $callbacks = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
        $readRouting = new ReflectionProperty(Connection::class, 'readOnWriteConnection');
        $previousReadRouting = $readRouting->getValue($connection);
        $connection->useWriteConnectionWhenReading();
        $previousReconnect = $reconnector->getValue($connection);
        $previousCallbacks = $callbacks->getValue($connection);
        $assertSession = static function () use ($connection, $pdo, $readRouting): void {
            if ($connection->getRawPdo() !== $pdo || $readRouting->getValue($connection) !== true) {
                throw new OwnershipViolation('The adoption connection session changed while holding its lock.');
            }
        };
        $connection->setReconnector(static function (): never {
            throw new OwnershipViolation('Reconnection is forbidden during adoption; resume in a new operation.');
        });
        $connection->beforeExecuting($assertSession);
        try {
            $result = $callback();
            $assertSession();

            return $result;
        } finally {
            $reconnector->setValue($connection, $previousReconnect);
            $callbacks->setValue($connection, $previousCallbacks);
            $readRouting->setValue($connection, $previousReadRouting);
            unset(self::$held[$connection]);
            $release();
        }
    }

    /** Return the matching release action only after acquiring a real storage lock. @return Closure(): void */
    private function acquire(Connection $connection, PDO $pdo): Closure
    {
        $driver = $connection->getDriverName();
        if ($driver === 'pgsql') {
            $statement = $pdo->query('SELECT pg_try_advisory_lock(1853254709, 1)');
            if ($statement === false || ! $statement->fetchColumn()) {
                throw new OwnershipViolation('Another adoption operation holds the connection lock.');
            }

            return static function () use ($pdo): void {
                $pdo->query('SELECT pg_advisory_unlock(1853254709, 1)');
            };
        }
        if ($driver === 'mysql' || $driver === 'mariadb') {
            $database = $pdo->query('SELECT DATABASE()');
            $name = 'nvl:adoption:'.substr(hash('sha256', (string) ($database === false ? '' : $database->fetchColumn())), 0, 48);
            $statement = $pdo->prepare('SELECT GET_LOCK(?, 0)');
            $statement->execute([$name]);
            if ((int) $statement->fetchColumn() !== 1) {
                throw new OwnershipViolation('Another adoption operation holds the connection lock.');
            }

            return static function () use ($pdo, $name): void {
                $statement = $pdo->prepare('SELECT RELEASE_LOCK(?)');
                $statement->execute([$name]);
            };
        }
        if ($driver !== 'sqlite') {
            throw new OwnershipConfigurationInvalid('This database driver has no supported adoption serialization lock.');
        }
        $database = $connection->getDatabaseName();
        if ($database === ':memory:') {
            return static function (): void {};
        }
        $path = realpath($database);
        if ($path === false) {
            throw new OwnershipConfigurationInvalid('SQLite adoption requires an existing local database file.');
        }
        $handle = fopen($path.'.nvl-adoption.lock', 'c');
        if ($handle === false || ! flock($handle, LOCK_EX | LOCK_NB)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new OwnershipViolation('Another adoption operation holds the connection lock.');
        }

        return static function () use ($handle): void {
            flock($handle, LOCK_UN);
            fclose($handle);
        };
    }
}
