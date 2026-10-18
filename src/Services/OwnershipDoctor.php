<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Config\Repository;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Throwable;

/** Read-only deployment and actual-storage checks. @internal */
final readonly class OwnershipDoctor
{
    /** Resolve declarations lazily, after application registration. */
    public function __construct(private Repository $configuration, private OwnershipConfiguration $options, private OwnershipResourceRegistry $resources, private OwnershipInstallationState $installation) {}

    /** @return list<array{key: string, severity: string, passed: bool, message: string}> */
    public function inspect(): array
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

            return [['key' => 'ownership.readiness', 'severity' => 'error', 'passed' => true, 'message' => 'Configuration and declared storage are ready.']];
        } catch (Throwable) {
            return [['key' => 'ownership.readiness', 'severity' => 'error', 'passed' => false, 'message' => 'Ownership configuration or actual storage is unavailable; verify adapters, immutable declarations, and complete adoption under maintenance.']];
        }
    }
}
