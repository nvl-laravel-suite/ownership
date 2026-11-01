# Public API: nvl/ownership

Generated from the package public contract. Use this reference to confirm fully qualified names, parameter order, defaults, native array shapes and return types. Read [usage](usage.md) for policy and operation semantics; declarations alone never grant authority.

Search for the relevant fully qualified service or contract and read only its matching symbol sections; this is a lookup catalog, not an instruction to load every declaration. `resolved` annotations expand imported aliases and domain type shapes. Framework inheritance and host-authored extensions remain outside this package contract.

## Symbols

- `Nvl\Ownership\Contracts\CentralAccess`
- `Nvl\Ownership\Contracts\OwnerAccess`
- `Nvl\Ownership\Contracts\OwnerAvailability`
- `Nvl\Ownership\Contracts\OwnerSiteResolver`
- `Nvl\Ownership\Contracts\OwnerSystemAccess`
- `Nvl\Ownership\Contracts\OwnershipActorResolver`
- `Nvl\Ownership\Contracts\OwnershipAdoptionAccess`
- `Nvl\Ownership\Contracts\OwnershipAdoptionAdapter`
- `Nvl\Ownership\Contracts\OwnershipAdoptionMetadataValidator`
- `Nvl\Ownership\Contracts\OwnershipAdoptionSource`
- `Nvl\Ownership\Contracts\OwnershipRunner`
- `Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter`
- `Nvl\Ownership\Services\OwnerIdentityResolver`
- `Nvl\Ownership\Services\OwnershipAdoptionCoordinator`
- `Nvl\Ownership\Services\OwnershipAdoptionRegistry`
- `Nvl\Ownership\Services\OwnershipWriter`
- `Nvl\Ownership\Services\PublicOwnerSiteRunner`
- `Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan`
- `Nvl\Ownership\ValueObjects\OwnershipAssignment`
- `Nvl\Ownership\ValueObjects\OwnershipBackfillResult`
- `Nvl\Ownership\ValueObjects\OwnershipMapping`
- `Nvl\Ownership\ValueObjects\OwnershipMigrationReview`
- `Nvl\Ownership\ValueObjects\OwnershipOperation`
- `Nvl\Ownership\ValueObjects\OwnershipVerification`

## Nvl\Ownership\Contracts\CentralAccess

```text
interface Nvl\Ownership\Contracts\CentralAccess
public abstract function assertCanRun(string $operation, Illuminate\Contracts\Auth\Authenticatable $actor): void
```

## Nvl\Ownership\Contracts\OwnerAccess

```text
interface Nvl\Ownership\Contracts\OwnerAccess
public abstract function assertCanEnter(Illuminate\Contracts\Auth\Authenticatable $actor, Illuminate\Database\Eloquent\Model $owner): void
```

## Nvl\Ownership\Contracts\OwnerAvailability

```text
interface Nvl\Ownership\Contracts\OwnerAvailability
public abstract function assertAvailable(Illuminate\Database\Eloquent\Model $owner): void
```

## Nvl\Ownership\Contracts\OwnerSiteResolver

```text
interface Nvl\Ownership\Contracts\OwnerSiteResolver
public abstract function resolve(string $origin): ?Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext
```

## Nvl\Ownership\Contracts\OwnerSystemAccess

```text
interface Nvl\Ownership\Contracts\OwnerSystemAccess
public abstract function assertCanRun(string $operation, Illuminate\Database\Eloquent\Model $owner): void
```

## Nvl\Ownership\Contracts\OwnershipActorResolver

```text
interface Nvl\Ownership\Contracts\OwnershipActorResolver
public abstract function resolve(string $type, string $id): Illuminate\Contracts\Auth\Authenticatable
```

## Nvl\Ownership\Contracts\OwnershipAdoptionAccess

```text
interface Nvl\Ownership\Contracts\OwnershipAdoptionAccess
public abstract function authorize(Nvl\Ownership\ValueObjects\OwnershipOperation $operation): void
```

## Nvl\Ownership\Contracts\OwnershipAdoptionAdapter

```text
interface Nvl\Ownership\Contracts\OwnershipAdoptionAdapter
public abstract function activate(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan): void
public abstract function backfill(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): Nvl\Ownership\ValueObjects\OwnershipBackfillResult
public abstract function prepare(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan): void
public abstract function resources(): array
@return list<string>
public abstract function verify(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan): Nvl\Ownership\ValueObjects\OwnershipVerification
```

## Nvl\Ownership\Contracts\OwnershipAdoptionMetadataValidator

```text
interface Nvl\Ownership\Contracts\OwnershipAdoptionMetadataValidator
public abstract function validateAssignment(Nvl\Ownership\ValueObjects\OwnershipMapping $assignment): void
```

## Nvl\Ownership\Contracts\OwnershipAdoptionSource

```text
interface Nvl\Ownership\Contracts\OwnershipAdoptionSource
public abstract function sourceFor(string $resource): Illuminate\Database\Eloquent\Model
```

## Nvl\Ownership\Contracts\OwnershipRunner

```text
interface Nvl\Ownership\Contracts\OwnershipRunner extends Nvl\Support\Ownership\Contracts\OwnershipSystemRunner
public abstract function central(string $operation, Illuminate\Contracts\Auth\Authenticatable $actor, Closure $callback): mixed
@template TResult
@param Closure(): TResult $callback
@return TResult
template Nvl\Ownership\Contracts\OwnershipRunner::TResult
public abstract function run(Illuminate\Database\Eloquent\Model $owner, Illuminate\Contracts\Auth\Authenticatable $actor, Closure $operation): mixed
@template TResult
@param Closure(): TResult $operation
@return TResult
template Nvl\Ownership\Contracts\OwnershipRunner::TResult
public abstract function runSystem(Nvl\Support\Ownership\ValueObjects\OwnerReference $owner, string $operation, Closure $callback): mixed
@template TResult
@param Closure(): TResult $callback
@return TResult
template Nvl\Ownership\Contracts\OwnershipRunner::TResult
public abstract function unowned(Closure $callback): mixed
@template TResult
@param Closure(): TResult $callback
@return TResult
template Nvl\Ownership\Contracts\OwnershipRunner::TResult
```

## Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter

```text
abstract class Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter implements Nvl\Ownership\Contracts\OwnershipAdoptionAdapter
public final function activate(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan): void
public final function backfill(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): Nvl\Ownership\ValueObjects\OwnershipBackfillResult
public final function prepare(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan): void
public abstract function resources(): array
@return list<string>
public final function verify(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan): Nvl\Ownership\ValueObjects\OwnershipVerification
```

## Nvl\Ownership\Services\OwnerIdentityResolver

```text
final readonly class Nvl\Ownership\Services\OwnerIdentityResolver
public function __construct(Nvl\Support\OwnerRegistry $owners, Illuminate\Contracts\Config\Repository $configuration, Nvl\Ownership\Services\OwnershipConnections $connections): mixed
public function reference(Illuminate\Database\Eloquent\Model $owner): Nvl\Support\Ownership\ValueObjects\OwnerReference
public function resolve(Nvl\Support\Ownership\ValueObjects\OwnerReference $reference): Illuminate\Database\Eloquent\Model
```

## Nvl\Ownership\Services\OwnershipAdoptionCoordinator

```text
final readonly class Nvl\Ownership\Services\OwnershipAdoptionCoordinator
public function __construct(Illuminate\Container\Container $container, Illuminate\Contracts\Config\Repository $configuration, Nvl\Ownership\Services\OwnershipConnections $connections, Nvl\Ownership\Services\OwnershipAdoptionGraph $graphs, Nvl\Ownership\Services\OwnershipAdoptionStore $store, Nvl\Ownership\Services\OwnershipAdoptionLock $lock, Nvl\Ownership\Services\OwnershipConfigurationFingerprint $ownership, Nvl\Ownership\Services\OwnershipOperationRecorder $audit, Nvl\Ownership\Services\OwnershipAdoptionScope $scope, Nvl\Ownership\Services\OwnershipAdoptionConservation $conservation, Nvl\Ownership\Services\OwnershipMigrationInput $migrationInput): mixed
public function activate(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan, Nvl\Ownership\ValueObjects\OwnershipOperation $operation): void
public function backfill(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan, int $limit, Nvl\Ownership\ValueObjects\OwnershipOperation $operation): bool
public function prepare(array $packages, iterable $mappings, Nvl\Ownership\ValueObjects\OwnershipOperation $operation, ?Nvl\Ownership\ValueObjects\OwnershipMigrationReview $review=null): Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan
@param list<string> $packages
@param iterable<OwnershipMapping> $mappings
resolved @param iterable<Nvl\Ownership\ValueObjects\OwnershipMapping> $mappings
public function refreshConstraints(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan, Nvl\Ownership\ValueObjects\OwnershipOperation $operation): void
public function resume(string $runId): Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan
public function verify(Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan $plan): Nvl\Ownership\ValueObjects\OwnershipVerification
```

## Nvl\Ownership\Services\OwnershipAdoptionRegistry

```text
final class Nvl\Ownership\Services\OwnershipAdoptionRegistry
public function register(string $package, string $adapter): void
@param class-string<OwnershipAdoptionAdapter> $adapter
resolved @param class-string<Nvl\Ownership\Contracts\OwnershipAdoptionAdapter> $adapter
```

## Nvl\Ownership\Services\OwnershipWriter

```text
final readonly class Nvl\Ownership\Services\OwnershipWriter
public function __construct(Illuminate\Container\Container $container): mixed
public function create(string $modelClass, Closure $persist): Illuminate\Database\Eloquent\Model
@template T of Model
resolved @template T of Illuminate\Database\Eloquent\Model
@param class-string<T> $modelClass
@param Closure(OwnershipAssignment): T $persist
resolved @param Closure(Nvl\Ownership\ValueObjects\OwnershipAssignment): T $persist
@return T
template Nvl\Ownership\Services\OwnershipWriter::T of Illuminate\Database\Eloquent\Model
```

## Nvl\Ownership\Services\PublicOwnerSiteRunner

```text
final readonly class Nvl\Ownership\Services\PublicOwnerSiteRunner
public function __construct(Illuminate\Container\Container $container): mixed
public function run(string $origin, Closure $callback): mixed
@template TResult
@param Closure(): TResult $callback
@return TResult
template Nvl\Ownership\Services\PublicOwnerSiteRunner::TResult
```

## Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan

```text
final readonly class Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan
public function __construct(string $id, string $connection, string $mappingHash, string $configurationHash): mixed
public readonly string $configurationHash
public readonly string $connection
public readonly string $id
public readonly string $mappingHash
```

## Nvl\Ownership\ValueObjects\OwnershipAssignment

```text
final readonly class Nvl\Ownership\ValueObjects\OwnershipAssignment
public function __construct(array $values): mixed
@param array<string, (int | string | null)> $values
public function attributes(): array
@return array<string, (int | string | null)>
```

## Nvl\Ownership\ValueObjects\OwnershipBackfillResult

```text
final readonly class Nvl\Ownership\ValueObjects\OwnershipBackfillResult
public function __construct(?string $nextCursor, int $processed): mixed
public readonly ?string $nextCursor
public readonly int $processed
```

## Nvl\Ownership\ValueObjects\OwnershipMapping

```text
final readonly class Nvl\Ownership\ValueObjects\OwnershipMapping
public function __construct(string $resource, string $recordId, ?Nvl\Support\Ownership\ValueObjects\OwnerReference $owner, array $metadata=[], ?string $legacyTenantId=null): mixed
@param array<string, mixed> $metadata
public function requireOwner(): Nvl\Support\Ownership\ValueObjects\OwnerReference
public readonly ?string $legacyTenantId
public readonly array $metadata
@param array<string, mixed> $metadata
public readonly ?Nvl\Support\Ownership\ValueObjects\OwnerReference $owner
public readonly string $recordId
public readonly string $resource
```

## Nvl\Ownership\ValueObjects\OwnershipMigrationReview

```text
final readonly class Nvl\Ownership\ValueObjects\OwnershipMigrationReview
public function __construct(string $id, array $packages, array $mappings, string $graphHash, string $configurationHash, array $sources): mixed
@param list<string> $packages
@param list<OwnershipMapping> $mappings
resolved @param list<Nvl\Ownership\ValueObjects\OwnershipMapping> $mappings
@param array<string, array{model: string, table: string, key: string, store: string, rows: int}> $sources
public function toArray(): array
@return array{version: int, id: string, packages: list<string>, graph_hash: string, configuration_hash: string, sources: array<string, array{model: string, table: string, key: string, store: string, rows: int}>, mappings: list<array{resource: string, record_id: string, owner: (array{version: int, type: string, storage: string, keyType: string, key: string} | null), metadata: array<string, mixed>, legacy_tenant_id: (string | null)}>}
public readonly string $configurationHash
public readonly string $graphHash
public readonly string $id
public readonly array $mappings
@param list<OwnershipMapping> $mappings
resolved @param list<Nvl\Ownership\ValueObjects\OwnershipMapping> $mappings
public readonly array $packages
@param list<string> $packages
public readonly array $sources
@param array<string, array{model: string, table: string, key: string, store: string, rows: int}> $sources
```

## Nvl\Ownership\ValueObjects\OwnershipOperation

```text
final readonly class Nvl\Ownership\ValueObjects\OwnershipOperation
public function __construct(string $purpose, string $actorType, string $actorId): mixed
public readonly string $actorId
public readonly string $actorType
public readonly string $purpose
```

## Nvl\Ownership\ValueObjects\OwnershipVerification

```text
final readonly class Nvl\Ownership\ValueObjects\OwnershipVerification
public function __construct(array $errors, array $resources=[]): mixed
@param list<string> $errors
@param array<string, ResourceReport> $resources
resolved @param array<string, array{source_store: string, source_table: string, target_table: string, source: int, persisted: int, mapped: int, owners: array<string, int>}> $resources
public function passed(): bool
public readonly array $errors
@param list<string> $errors
public readonly array $resources
@param array<string, ResourceReport> $resources
resolved @param array<string, array{source_store: string, source_table: string, target_table: string, source: int, persisted: int, mapped: int, owners: array<string, int>}> $resources
```
