<?php

declare(strict_types=1);

namespace Nvl\Ownership\ValueObjects;

/**
 * Reviewed deployment input; the document and audit actor grant no authorization.
 *
 * @api
 */
final readonly class OwnershipMigrationReview
{
    /** Retain the complete reviewed graph and explicit assignments.
     *
     * @param  list<string>  $packages
     * @param  list<OwnershipMapping>  $mappings
     * @param  array<string, array{model: string, table: string, key: string, store: string, rows: int}>  $sources
     */
    public function __construct(public string $id, public array $packages, public array $mappings, public string $graphHash, public string $configurationHash, public array $sources) {}

    /** Serialize a reviewable document without omitting central dispositions or historical identities.
     *
     * @return array{version: int, id: string, packages: list<string>, graph_hash: string, configuration_hash: string, sources: array<string, array{model: string, table: string, key: string, store: string, rows: int}>, mappings: list<array{resource: string, record_id: string, owner: array{version: int, type: string, storage: string, keyType: string, key: string}|null, metadata: array<string, mixed>, legacy_tenant_id: string|null}>}
     */
    public function toArray(): array
    {
        return ['version' => 1, 'id' => $this->id, 'packages' => $this->packages, 'graph_hash' => $this->graphHash, 'configuration_hash' => $this->configurationHash, 'sources' => $this->sources,
            'mappings' => array_map(static fn (OwnershipMapping $mapping): array => ['resource' => $mapping->resource, 'record_id' => $mapping->recordId, 'owner' => $mapping->owner?->toArray(), 'metadata' => $mapping->metadata, 'legacy_tenant_id' => $mapping->legacyTenantId], $this->mappings)];
    }
}
