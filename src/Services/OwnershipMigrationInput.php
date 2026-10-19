<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Support\Str;
use JsonException;
use Nvl\Ownership\Contracts\OwnershipAdoptionSource;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipMigrationReview;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/** Imports bounded explicit mappings and compares reviewed deployment identities without writing storage.
 *
 * @internal
 *
 * @phpstan-import-type Graph from OwnershipAdoptionGraph
 */
final readonly class OwnershipMigrationInput
{
    /** Retain code-owned graph, source and configuration identities. */
    public function __construct(private OwnershipAdoptionGraph $graphs, private OwnershipResourceRegistry $resources, private OwnershipConfigurationFingerprint $configuration, private OwnershipConnections $connections) {}

    /** Produce a read-only review from a host mapping file; no implicit owner assignment is permitted. */
    public function plan(string $path): OwnershipMigrationReview
    {
        $input = $this->read($path);
        $this->assertKeys($input, ['packages', 'mappings']);
        $packages = $this->packages($input['packages'] ?? null);
        $graph = $this->graphs->resolve($packages);

        return new OwnershipMigrationReview((string) Str::uuid(), $graph['packages'], $this->mappings($input['mappings'] ?? null), $graph['manifest'], $this->configuration->hash($graph['resources']), $this->sources($graph));
    }

    /** Reopen a reviewed document and require its immutable identifier and current source identities. */
    public function reviewed(string $path, string $id): OwnershipMigrationReview
    {
        $input = $this->read($path);
        $this->assertKeys($input, ['version', 'id', 'packages', 'graph_hash', 'configuration_hash', 'sources', 'mappings']);
        if ($input['version'] !== 1 || $input['id'] !== $id || ! Str::isUuid($id)
            || ! is_string($input['graph_hash']) || ! is_string($input['configuration_hash'])) {
            throw new OwnershipConfigurationInvalid('The reviewed plan identifier or version is invalid.');
        }
        $graph = $this->graphs->resolve($this->packages($input['packages']));
        $sources = $this->sources($graph);
        if (! is_array($input['sources']) || count($input['sources']) !== count($sources)) {
            throw new OwnershipConfigurationInvalid('The reviewed physical source inventory changed.');
        }
        foreach ($sources as $resource => $expected) {
            $actual = $input['sources'][$resource] ?? null;
            if (! is_array($actual) || count($actual) !== count($expected)) {
                throw new OwnershipConfigurationInvalid('The reviewed physical source inventory changed.');
            }
            foreach ($expected as $field => $value) {
                if (($actual[$field] ?? null) !== $value) {
                    throw new OwnershipConfigurationInvalid('The reviewed physical source inventory changed. Generate and review a new plan before preparation.');
                }
            }
        }
        $review = new OwnershipMigrationReview($id, $graph['packages'], $this->mappings($input['mappings']), $input['graph_hash'], $input['configuration_hash'], $sources);
        $this->assertCurrent($review, $graph);

        return $review;
    }

    /** Compare the same facts again inside the coordinator's source leases immediately before mutation.
     *
     * @param  Graph  $graph
     */
    public function assertCurrent(OwnershipMigrationReview $review, array $graph): void
    {
        if ($review->packages !== $graph['packages'] || ! hash_equals($graph['manifest'], $review->graphHash)
            || ! hash_equals($this->configuration->hash($graph['resources']), $review->configurationHash)
            || $review->sources !== $this->sources($graph)) {
            throw new OwnershipConfigurationInvalid('The reviewed model, graph, source or configuration changed.');
        }
    }

    /** Count raw history, including soft-deleted rows, on each exact physical source.
     *
     * @param  Graph  $graph
     * @return array<string, array{model: string, table: string, key: string, store: string, rows: int}>
     */
    private function sources(array $graph): array
    {
        $sources = [];
        foreach ($graph['resources'] as $resource) {
            $adapter = $graph['adapters'][$graph['owners'][$resource]];
            $model = $adapter instanceof OwnershipAdoptionSource ? $adapter->sourceFor($resource) : new ($this->resources->get($resource)->model);
            $connection = $model->getConnection();
            $sources[$resource] = ['model' => $model::class, 'table' => $model->getTable(), 'key' => $model->getKeyName(), 'store' => $this->connections->identity($connection), 'rows' => $connection->getSchemaBuilder()->hasTable($model->getTable()) ? $connection->table($model->getTable())->useWritePdo()->count() : 0];
        }

        return $sources;
    }

    /** Decode at most sixteen MiB without evaluating PHP, class names or executable input.
     *
     * @return array<string, mixed>
     */
    private function read(string $path): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new OwnershipConfigurationInvalid('The mapping input must be a readable JSON file.');
        }
        $stream = fopen($path, 'rb');
        if ($stream === false) {
            throw new OwnershipConfigurationInvalid('Unable to open the mapping input.');
        }
        try {
            $bytes = stream_get_contents($stream, 16777217);
        } finally {
            fclose($stream);
        }
        if ($bytes === false || strlen($bytes) > 16777216) {
            throw new OwnershipConfigurationInvalid('Mapping input exceeds sixteen MiB.');
        }
        try {
            $input = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new OwnershipConfigurationInvalid('Mapping input must be valid JSON.');
        }
        if (! is_array($input) || array_is_list($input)) {
            throw new OwnershipConfigurationInvalid('Mapping input must be an object.');
        }
        $result = [];
        foreach ($input as $key => $value) {
            if (! is_string($key)) {
                throw new OwnershipConfigurationInvalid('Mapping input fields must be named.');
            }
            $result[$key] = $value;
        }

        return $result;
    }

    /** Require a closed document shape so misspelled authority fields cannot be silently ignored.
     *
     * @param  array<string, mixed>  $input
     * @param  list<string>  $keys
     */
    private function assertKeys(array $input, array $keys): void
    {
        $actual = array_keys($input);
        sort($actual);
        sort($keys);
        if ($actual !== $keys) {
            throw new OwnershipConfigurationInvalid('The mapping document has missing or unknown fields.');
        }
    }

    /** @return list<string> */
    private function packages(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value) || $value === [] || count($value) > 100) {
            throw new OwnershipConfigurationInvalid('Select a nonempty bounded package list.');
        }
        $packages = [];
        foreach ($value as $package) {
            if (! is_string($package) || $package === '' || strlen($package) > 100) {
                throw new OwnershipConfigurationInvalid('Package names must be bounded strings.');
            }
            $packages[] = $package;
        }

        return $packages;
    }

    /** Decode explicit per-record dispositions, preserving full native and legacy identities.
     *
     * @return list<OwnershipMapping>
     */
    private function mappings(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new OwnershipConfigurationInvalid('Mappings must be a list of explicit record dispositions.');
        }
        $mappings = [];
        $seen = [];
        foreach ($value as $row) {
            if (! is_array($row) || ! is_string($row['resource'] ?? null) || ! is_string($row['record_id'] ?? null)
                || ! array_key_exists('owner', $row) || ($row['owner'] !== null && ! is_array($row['owner']))
                || array_diff(array_keys($row), ['resource', 'record_id', 'owner', 'metadata', 'legacy_tenant_id']) !== []
                || (isset($row['legacy_tenant_id']) && ! is_string($row['legacy_tenant_id'])) || ! is_array($row['metadata'] ?? [])) {
                throw new OwnershipConfigurationInvalid('Each mapping requires exact resource, record_id and explicit owner fields.');
            }
            $metadata = [];
            foreach ($row['metadata'] ?? [] as $key => $item) {
                if (! is_string($key)) {
                    throw new OwnershipConfigurationInvalid('Mapping metadata must be a named object.');
                }
                $metadata[$key] = $item;
            }
            $identity = json_encode([$row['resource'], $row['record_id']], JSON_THROW_ON_ERROR);
            if (isset($seen[$identity])) {
                throw new OwnershipConfigurationInvalid('Duplicate mapping disposition.');
            }
            $seen[$identity] = true;
            $mappings[] = new OwnershipMapping($row['resource'], $row['record_id'], $row['owner'] === null ? null : OwnerReference::fromArray($row['owner']), $metadata, $row['legacy_tenant_id'] ?? null);
        }

        return $mappings;
    }
}
