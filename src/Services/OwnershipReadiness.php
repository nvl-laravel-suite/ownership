<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Nvl\Support\Ownership\Contracts\OwnershipReadiness as ReadinessContract;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

/**
 * Checks storage readiness without changing execution authority.
 *
 * @internal
 */
final readonly class OwnershipReadiness implements ReadinessContract
{
    /** Read fresh installation facts rather than a cached bootstrap result. */
    public function __construct(private OwnershipConfiguration $configuration, private OwnershipInstallationState $installation, private OwnershipSchema $schema) {}

    /** Require the selected resource in the committed graph and its live physical constraints. */
    public function assertActive(string $resource): void
    {
        $this->configuration->assertReady();
        if (! isset($this->installation->generations()[$resource])) {
            throw new OwnershipSchemaNotReady('The requested resource is outside the active ownership graph.');
        }
        $this->schema->assertReady($resource);
    }
}
