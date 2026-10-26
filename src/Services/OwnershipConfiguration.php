<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Events\TransactionCommitting;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Validates explicit runtime authority and immutable resource graph completeness.
 *
 * @internal
 */
final readonly class OwnershipConfiguration
{
    /** Retain configuration and resource declarations only. */
    public function __construct(private Repository $configuration, private OwnershipResourceRegistry $resources, private OwnershipIntegrationReadiness $readiness) {}

    /** Deny simultaneous authorities and malformed runtime switches. */
    public function validate(): void
    {
        $enabled = $this->configuration->get('nvl-ownership.enabled');
        if (! is_bool($enabled) || ($enabled && $this->configuration->get('nvl-tenancy.enabled') === true)) {
            throw new OwnershipConfigurationInvalid('Ownership requires one explicit enforcing runtime.');
        }
    }

    /** Require explicit activation and a complete ownership graph. */
    public function assertReady(): void
    {
        $this->validate();
        if ($this->configuration->get('nvl-ownership.enabled') !== true) {
            throw new OwnershipViolation('Ownership execution is disabled.');
        }
        if (! class_exists(TransactionCommitting::class)) {
            throw new OwnershipConfigurationInvalid('The framework must support ownership fencing before native transaction commit.');
        }
        $this->resources->freeze();
        $this->readiness->assertReady();
    }
}
