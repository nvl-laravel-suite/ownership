<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use ReflectionClass;

/**
 * Retains package-owned adoption adapter class names without scoped runtime state.
 *
 * @api
 */
final class OwnershipAdoptionRegistry
{
    /** @var array<string, class-string<OwnershipAdoptionAdapter>> */
    private array $adapters = [];

    /** @param class-string<OwnershipAdoptionAdapter> $adapter */
    public function register(string $package, string $adapter): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,190}$/D', $package) !== 1 || ! class_exists($adapter)
            || ! (new ReflectionClass($adapter))->implementsInterface(OwnershipAdoptionAdapter::class) || ! (new ReflectionClass($adapter))->isInstantiable()
            || (isset($this->adapters[$package]) && $this->adapters[$package] !== $adapter)) {
            throw new OwnershipConfigurationInvalid('Invalid or conflicting adoption adapter registration.');
        }
        $this->adapters[$package] = $adapter;
    }

    /**
     * Return immutable registrations for internal graph resolution.
     *
     * @internal
     *
     * @return array<string, class-string<OwnershipAdoptionAdapter>>
     */
    public function all(): array
    {
        return $this->adapters;
    }
}
