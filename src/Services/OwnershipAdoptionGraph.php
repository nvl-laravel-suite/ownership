<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Container\Container;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Contracts\OwnershipAdoptionSource;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;

/**
 * Resolves the immutable selected graph and canonical-parent closure.
 *
 * @internal
 *
 * @phpstan-type Graph array{packages: list<string>, resources: list<string>, adapters: array<string, OwnershipAdoptionAdapter>, owners: array<string, string>, manifest: string}
 */
final readonly class OwnershipAdoptionGraph
{
    /** Resolve scoped adapters only while performing one adoption phase. */
    public function __construct(private Container $container, private OwnershipAdoptionRegistry $adoptions, private OwnershipResourceRegistry $resources, private OwnershipConnections $connections) {}

    /**
     * Select the named packages and the packages of their canonical parents.
     *
     * A parent package that was not named stays outside the run once all of its resources are active,
     * so a later deployment can adopt new children without replacing the parents' generation.
     *
     * @param  list<string>  $packages
     * @return Graph
     */
    public function resolve(array $packages): array
    {
        if ($packages === []) {
            throw new OwnershipConfigurationInvalid('Select a registered adoption package.');
        }
        $this->resources->freeze();
        $adapters = [];
        $owners = [];
        $registered = $this->adoptions->all();
        foreach ($registered as $package => $class) {
            $adapter = $this->container->make($class);
            if (! $adapter instanceof OwnershipAdoptionAdapter) {
                throw new OwnershipConfigurationInvalid('The configured adoption binding violates its phase contract.');
            }
            $adapters[$package] = $adapter;
            foreach ($adapter->resources() as $key) {
                $this->resources->get($key);
                if (isset($owners[$key])) {
                    throw new OwnershipConfigurationInvalid('A resource requires exactly one adoption adapter.');
                }
                $owners[$key] = $package;
            }
        }
        $ordered = [];
        $visiting = [];
        $visit = function (string $package) use (&$visit, &$ordered, &$visiting, $adapters, $owners, $packages): void {
            if (isset($ordered[$package])) {
                return;
            }
            if (! isset($adapters[$package]) || isset($visiting[$package])) {
                throw new OwnershipConfigurationInvalid('Unknown or cyclic adoption package.');
            }
            $visiting[$package] = true;
            foreach ($adapters[$package]->resources() as $key) {
                foreach ($this->resources->get($key)->parents() as $parent) {
                    $parentPackage = $owners[$parent] ?? throw new OwnershipConfigurationInvalid('A parent has no adoption adapter.');
                    if ($parentPackage !== $package && (in_array($parentPackage, $packages, true) || ! isset($adapters[$parentPackage]) || ! $this->active($adapters[$parentPackage]))) {
                        $visit($parentPackage);
                    }
                }
            }
            unset($visiting[$package]);
            $ordered[$package] = $adapters[$package];
        };
        sort($packages);
        foreach (array_unique($packages) as $package) {
            $visit($package);
        }
        $keys = [];
        $manifest = [];
        foreach ($ordered as $package => $adapter) {
            $owned = $adapter->resources();
            sort($owned);
            $sources = [];
            foreach ($owned as $key) {
                if ($this->resources->get($key)->ownership->strategy === 'unscoped') {
                    throw new OwnershipConfigurationInvalid('Unscoped resources cannot be adopted; configure native primary ownership before selecting ['.$key.'].');
                }
                $source = $adapter instanceof OwnershipAdoptionSource ? $adapter->sourceFor($key) : new ($this->resources->get($key)->model);
                if ($source->getConnection() !== (new ($this->resources->get($key)->model))->getConnection()) {
                    throw new OwnershipConfigurationInvalid('Historical adoption must preserve its canonical actual source connection.');
                }
                $sources[$key] = [$source::class, $source->getTable(), $source->getKeyName(), $source->getKeyType(), $source->getConnection()->getName(), $source->getConnection()->getTablePrefix()];
            }
            $manifest[$package] = [$registered[$package], $adapter::class, $owned, $sources];
            array_push($keys, ...$owned);
        }
        sort($keys);
        $packages = array_keys($ordered);
        sort($packages);

        return ['packages' => $packages, 'resources' => $keys, 'adapters' => $ordered, 'owners' => array_intersect_key($owners, array_flip($keys)), 'manifest' => hash('sha256', json_encode($manifest, JSON_THROW_ON_ERROR))];
    }

    /** Read the live source markers; the children's constraints and every later admission still verify the parents' committed generation. */
    private function active(OwnershipAdoptionAdapter $adapter): bool
    {
        foreach ($adapter->resources() as $key) {
            if ($this->connections->resource($key)->table(Tables::Markers)->useWritePdo()->where('resource', $key)->value('state') !== 'active') {
                return false;
            }
        }

        return $adapter->resources() !== [];
    }
}
