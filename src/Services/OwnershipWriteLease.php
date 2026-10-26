<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseTransactionsManager;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use PDO;
use ReflectionProperty;
use Throwable;
use WeakMap;

/**
 * Pins native SQL writes to the actual source session until canonical verification completes.
 *
 * @internal
 */
final class OwnershipWriteLease
{
    /** @var WeakMap<Connection, true>|null */
    private static ?WeakMap $registered = null;

    /** @var list<array{connection: Connection, pdo: PDO, level: int, name: string, callbacks: list<Closure>}> */
    private array $frames = [];

    private bool $tainted = false;

    /** Resolve current context cleanup at the framework execution boundary. */
    public function __construct(private readonly Container $container) {}

    /** Attach one application-neutral SQL hook to each native connection. */
    public static function register(Connection $connection): void
    {
        self::$registered ??= new WeakMap;
        if (isset(self::$registered[$connection])) {
            return;
        }
        $connection->beforeExecuting(static function (string $query, array $bindings, Connection $connection): void {
            $app = Container::getInstance();
            if ($app->bound(self::class)) {
                $app->make(self::class)->assertQueryAllowed($connection, $query);
            }
        });
        self::$registered[$connection] = true;
    }

    /**
     * Confine a balanced persistence callback to one source transaction and PDO.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function during(Connection $connection, Closure $callback): mixed
    {
        if ($this->tainted || $connection->transactionLevel() === 0) {
            throw new OwnershipViolation('Owned persistence requires an intact actual source transaction.');
        }
        foreach ($this->frames as $frame) {
            if ($frame['connection'] !== $connection && $frame['connection']->transactionLevel() > 0) {
                throw new OwnershipViolation('Owned writes cannot span independently committing source connections.');
            }
        }
        self::register($connection);
        $pdo = $connection->getPdo();
        $level = $connection->transactionLevel();
        $name = $connection->getName();
        if (! is_string($name) || $name === '') {
            throw new OwnershipConfigurationInvalid('Owned callbacks require a named native connection.');
        }
        $manager = (new ReflectionProperty(Connection::class, 'transactionsManager'))->getValue($connection);
        if ($manager !== null && ! $manager instanceof DatabaseTransactionsManager) {
            throw new OwnershipConfigurationInvalid('Owned writes require the native transaction callback manager.');
        }
        $callbacksProperty = new ReflectionProperty(Connection::class, 'beforeExecutingCallbacks');
        $reconnectorProperty = new ReflectionProperty(Connection::class, 'reconnector');
        $previousCallbacks = $callbacksProperty->getValue($connection);
        $previousReconnector = $reconnectorProperty->getValue($connection);
        if (! is_array($previousCallbacks) || ! array_is_list($previousCallbacks)) {
            throw new OwnershipConfigurationInvalid('Owned writes require the native ordered SQL callback list.');
        }
        $validatedCallbacks = [];
        foreach ($previousCallbacks as $sqlCallback) {
            if (! $sqlCallback instanceof Closure) {
                throw new OwnershipConfigurationInvalid('SQL callbacks must use the native closure contract.');
            }
            $validatedCallbacks[] = $sqlCallback;
        }
        $tail = function (string $query) use ($connection, $callbacksProperty): void {
            $expected = null;
            foreach ($this->frames as $frame) {
                if ($frame['connection'] === $connection) {
                    $expected = $frame['callbacks'];
                }
            }
            if ($expected === null || $callbacksProperty->getValue($connection) !== $expected) {
                throw new OwnershipViolation('The SQL callback chain changed inside owned persistence.');
            }
            $this->assertQueryAllowed($connection, $query);
        };
        $callbacks = [...$validatedCallbacks, $tail];
        $callbacksProperty->setValue($connection, $callbacks);
        $connection->setReconnector(static function (): never {
            throw new OwnershipViolation('Owned persistence cannot reconnect its source session.');
        });
        $this->frames[] = compact('connection', 'pdo', 'level', 'name', 'callbacks');
        try {
            $result = $callback();
            if ($connection->getRawPdo() !== $pdo || $connection->getName() !== $name || $connection->transactionLevel() !== $level || ! $pdo->inTransaction()) {
                throw new OwnershipViolation('The owned source session or transaction balance changed inside persistence.');
            }

            return $result;
        } catch (Throwable $exception) {
            if ($connection->getRawPdo() !== $pdo || $connection->getName() !== $name || $connection->transactionLevel() !== $level || ! $pdo->inTransaction()) {
                $this->tainted = true;
                $this->container->make(ScopedOwnershipContext::class)->invalidate();
                try {
                    if ($pdo->inTransaction()) {
                        $pdo->rollBack();
                    }
                } finally {
                    $connection->setPdo($pdo);
                    $manager?->rollback($name, 0);
                }
            }
            throw $exception;
        } finally {
            $callbacksProperty->setValue($connection, $previousCallbacks);
            $reconnectorProperty->setValue($connection, $previousReconnector);
            array_pop($this->frames);
        }
    }

    /** Deny physical commit from inside a persistence callback before any data or callback escapes. */
    public function assertCommitAllowed(Connection $connection): void
    {
        foreach ($this->frames as $frame) {
            if ($frame['connection'] === $connection) {
                throw new OwnershipViolation('Native commit must follow canonical ownership verification.');
            }
        }
    }

    /** Permit remote metadata reads while forbidding redirected mutations, DDL and source reconnection. */
    public function assertQueryAllowed(Connection $connection, string $query): void
    {
        $read = preg_match('/^\s*select\b/i', $query) === 1;
        foreach ($this->frames as $frame) {
            $source = $frame['connection'];
            if ($source->getRawPdo() !== $frame['pdo'] || $source->getName() !== $frame['name'] || $source->transactionLevel() < $frame['level'] || ! $frame['pdo']->inTransaction()
                || ($connection !== $source && ($connection->getName() === $source->getName() || ! $read))
                || ($connection === $source && preg_match('/^\s*(?:select|with|insert|update|delete|savepoint|release|rollback)\b/i', $query) !== 1)) {
                throw new OwnershipViolation('The SQL operation escaped its owned source-session lease.');
            }
        }
    }
}
