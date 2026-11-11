<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\Traits\ScopesOwnedBuilderWrites;
use Throwable;

/**
 * Read-only deployment and actual-storage checks.
 *
 * @internal
 */
final readonly class OwnershipDoctor
{
    /** Resolve declarations lazily, after application registration. */
    public function __construct(private Repository $configuration, private OwnershipConfiguration $options, private OwnershipResourceRegistry $resources, private OwnershipInstallationState $installation) {}

    /** @return list<array{key: string, severity: string, passed: bool, message: string}> */
    public function inspect(): array
    {
        return [$this->readiness(), $this->builders()];
    }

    /** @return array{key: string, severity: string, passed: bool, message: string} */
    private function readiness(): array
    {
        try {
            $this->options->validate();
            if ($this->configuration->get('nvl-ownership.enabled') === true) {
                $this->options->assertReady();
                $this->installation->generations();
            } else {
                foreach ($this->resources->all() as $resource) {
                    $model = new $resource->model;
                    (new PersistedOwnershipStorage)->assertUnadopted($model->getConnection(), $resource->key, $model->getTable());
                }
            }

            return ['key' => 'ownership.readiness', 'severity' => 'error', 'passed' => true, 'message' => 'Configuration and declared storage are ready.'];
        } catch (Throwable) {
            return ['key' => 'ownership.readiness', 'severity' => 'error', 'passed' => false, 'message' => 'Ownership configuration or actual storage is unavailable; verify adapters, immutable declarations, and complete adoption under maintenance.'];
        }
    }

    /**
     * Name owned models whose custom builder runs Laravel's scope-free writes outside the boundary.
     *
     * @return array{key: string, severity: string, passed: bool, message: string}
     */
    private function builders(): array
    {
        $unguarded = [];
        $unreadable = [];
        foreach ($this->resources->all() as $resource) {
            if ($resource->ownership->strategy === 'unscoped') {
                continue;
            }
            try {
                if (! in_array(ScopesOwnedBuilderWrites::class, class_uses_recursive((new $resource->model)->newModelQuery()), true)) {
                    $unguarded[] = $resource->model;
                }
            } catch (Throwable) {
                $unreadable[] = $resource->model;
            }
        }
        if ($unreadable !== []) {
            return ['key' => 'ownership.builders', 'severity' => 'warning', 'passed' => false, 'message' => 'The builders of ['.implode(', ', $unreadable).'] could not be inspected; verify that each model and its connection can be constructed.'];
        }

        return ['key' => 'ownership.builders', 'severity' => 'warning', 'passed' => $unguarded === [], 'message' => $unguarded === []
            ? 'Owned model builders keep scope-free writes inside the boundary.'
            : 'Use ScopesOwnedBuilderWrites on the custom builders of ['.implode(', ', $unguarded).']; their forceDelete(), updateOrInsert() and updateFrom() are not owner-scoped.'];
    }
}
