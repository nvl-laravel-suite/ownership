# NVL Ownership — API and usage

[← NVL Laravel Suite](https://github.com/nvl-laravel-suite)

## Quickstart

Version 6.0.0 is published on Packagist. An ordinary unowned host does not need
this package. For isolated Media,
install the selected packages, keep the runtime disabled, and follow the model,
admission and adoption sections below before enabling traffic:

```sh
composer require nvl/ownership:^6.0 nvl/media:^6.0
php artisan vendor:publish --tag=nvl-ownership-config
php artisan vendor:publish --tag=nvl-ownership-skills
php artisan nvl:ownership:doctor --strict --format=json
```

Installation and Doctor output do not adopt existing storage or authorize an
actor. Bind host admission, select migrations, and complete reviewed adoption.

## Purpose

Optional ownership isolation for ordinary host Eloquent models. A consumer chooses
an unchanged native owner key and declares either a column or an exclusive concrete
pivot on a supported package model subclass. Ownership supplies admission, canonical
reads, immutable assignment, source transaction fences, scalar queue capture, and
reviewed adoption. It installs no Company, User, Tenant, membership or role models.
Host authorization remains required inside an admitted owner.

The 6.0.0 implementation covers the stateful NVL package graph, including
Auth, Tasks, CSV, Mail, Billing, Payments and Translations. Install only the domain
packages you need. Tenancy remains an explicitly selected migration bridge;
ordinary unowned applications need neither enforcement runtime. The release passed
the native ownership cutover gate and a fresh public Composer installation.
Deployment-specific cutover rehearsal remains required before enabling isolation.

## Quick reference

| Item | Value |
|---|---|
| Installed through | `composer require nvl/ownership:^6.0` |
| Module identifier | `nvl/ownership` |
| PHP namespace | `Nvl\Ownership` |
| Service provider | `Nvl\Ownership\Providers\OwnershipServiceProvider` |
| Configuration | `config/nvl-ownership.php` |

## Requirements and installation

NVL Light requires PHP 8.4 and Laravel 13. Enabled isolation requires Laravel's transaction-committing boundary and fails closed when that capability is unavailable. Native archive consumers passed on PostgreSQL 17 and MySQL 8.4 with Redis and
private storage, including worker and runtime-removal checks. Complete MariaDB
and other platform combinations require separate evidence.

```sh
composer require nvl/ownership:^6.0 nvl/media:^6.0
php artisan vendor:publish --tag=nvl-ownership-config
php artisan vendor:publish --tag=nvl-ownership-skills
```

Laravel discovers the provider. `enabled` and `migrations.enabled` both default to
false. NVL Auth and Tenancy are optional; register ordinary host owner **and actor**
classes in `nvl-core.owners`. Native morph aliases stay in Laravel's morph map.
Do not change existing aliases without reconciling persisted values.

## Model declaration and Media configuration

```php
namespace App\Models;

use Nvl\Media\Models\Media as BaseMedia;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

final class Media extends BaseMedia
{
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(Company::class, 'company_id');
    }
}
```

```php
// config/nvl-core.php
'owners' => [App\Models\Company::class, App\Models\User::class],

// config/nvl-media.php
'models' => ['media' => App\Models\Media::class],
```

Media's base already implements `Nvl\Support\Ownership\Contracts\Ownable` and
uses `Nvl\Support\Ownership\Traits\HasOwnership`. New host-owned domain models
opt in with that interface and trait and register a finite code-owned resource
through `Nvl\Support\Ownership\Services\OwnershipResourceRegistry`. Package
providers own their resource graph; changing a model declaration does not adopt
existing storage. Preserve Media's casts, builder, UUID and persistence hooks.

For an existing integer Company key, the host migration adds a matching native
`company_id` foreign key to the effective Media table. It may be nullable during
reviewed expansion. No host primary key is converted to UUID. Alternatively return:

```php
OwnershipDefinition::pivot(
    Company::class, 'company_media_ownership', 'media_id', 'company_id',
);
```

The host creates that concrete pivot with a UUID `media_id` foreign key to Media
and a native integer `company_id` foreign key to Company. One primary assignment
per asset is enforced; sharing permissions belong in separate grants. The adapter
adds technical partition columns and constraints. `OwnershipDefinition::inherited()`
is for package-approved canonical parent relations. Package operational roots use
captured scalar ownership internally. The default `unscoped()` declaration leaves
ordinary applications usable without an owner context or protocol tables.

## Admission and upload

Bind `Nvl\Ownership\Contracts\OwnerAccess` to a host policy implementing
`assertCanEnter(Illuminate\Contracts\Auth\Authenticatable $actor,
Illuminate\Database\Eloquent\Model $owner): void`. The default denies. Check actual
membership/status there; a request's Company ID is a selector, not authority.
The default `OwnershipActorResolver` reloads registered native Laravel actors;
replace that contract when the host needs different restoration. `OwnerAvailability`
can enforce host owner lifecycle rules.

For model-facing Media, Company implements `Nvl\Media\Contracts\HasNvlMedia` and
uses `Nvl\Media\Traits\InteractsWithNvlMedia`. Declare a slot:

```php
public function registerNvlMedia(
    \Nvl\Media\MediaRegistration $media,
    ?\Nvl\Media\Models\Media $asset = null,
): void {
    $media->slot('document')->oneToOne()->useDisk('private');
}
```

After adoption, resolve the Company and authenticated Laravel actor server-side,
then inject `Nvl\Ownership\Contracts\OwnershipRunner` into the host controller or
service:

```php
$asset = $runner->run($company, $actor, function () use ($company, $actor, $file) {
    return $company->nvlMedia()->add($file)->uploadedBy($actor)->slot('document');
});
```

The uploaded asset is the configured subclass. The admitted Company itself can be
its association target; owned business records can also be targets after canonical
resource registration. `uploadedBy()` records attribution and does not admit an
owner. Configure the private disk and package disk allowlist explicitly. Host
middleware resolves and admits the owner before package route binding. Signing a
private URL does not establish a context. Runner exit restores the previous scope,
locale and participant state, including on failure. Enter before opening a source
transaction; switching owners inside participating transactions is rejected.

Media coordinates its first persistence automatically through the neutral Core
writer. For a custom owned root, use the public runtime writer:

```php
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;

$record = $writer->create(Document::class, function (OwnershipAssignment $assignment) {
    $record = new Document;
    $record->forceFill([...$assignment->attributes(), 'title' => 'Example'])->save();
    return $record;
});
```

The source transaction includes the native pivot assignment and canonical check.
Quiet saves/deletes and native queued-model restoration retain the boundary.
Loaded relations, submitted owner fields and cached hash keys confer no authority.

## Adoption and migration ownership

For a fresh, empty host schema, extend the public
`Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter`. Only declare your
resource keys, with parents before their children:

```php
use Nvl\Ownership\Services\FreshHostOwnershipAdoptionAdapter;

final class DocumentOwnershipAdoption extends FreshHostOwnershipAdoptionAdapter
{
    public function resources(): array
    {
        return ['app.documents', 'app.document-versions'];
    }
}
```

Register the host models and adapter in a service provider's `boot()` method,
using injected `OwnershipResourceRegistry` and `OwnershipAdoptionRegistry`:

```php
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

$resources->register(new OwnershipResourceDefinition(
    'app.documents', 'host-documents', Document::class, Document::ownershipDefinition(),
));
$resources->register(new OwnershipResourceDefinition(
    'app.document-versions', 'host-documents', DocumentVersion::class,
    DocumentVersion::ownershipDefinition(), parentResource: 'app.documents',
));
$adoptions->register('host-documents', DocumentOwnershipAdoption::class);
```

The models implement `Ownable` and use `HasOwnership` as described above. Their
host migrations retain real, type-matching owner and parent foreign keys. Use the
same pattern for other host roots and their declared children. Every
inherited parent needs a registered adapter even when its table is empty.

After the protocol migration, configure the final graph under authorized
maintenance and inject `OwnershipAdoptionCoordinator` into the maintenance command:

```php
$operation = new OwnershipOperation('fresh-documents', 'operator', $operatorId);
$plan = $coordinator->prepare(['host-documents'], [], $operation);
while (! $coordinator->backfill($plan, 100, $operation)) {
}
if (! $coordinator->verify($plan)->passed()) {
    throw new RuntimeException('Host ownership verification failed.');
}
$coordinator->activate($plan, $operation);
```

The helper installs native partition columns and immutable constraints through
Ownership's schema implementation. The coordinator still checks authorization,
maintenance, graph closure, mapping conservation and activation; the helper cannot
be called independently to manufacture readiness. It refuses nonempty resources.
Existing records require a dedicated reviewed adoption adapter and mappings.
After activation, normal host writes use `OwnershipWriter` inside an admitted
`OwnershipRunner` operation. Do not call schema internals or write readiness markers.

For automatic vendor migrations, set `nvl-ownership.migrations.enabled=true` and
do not publish `nvl-ownership-migrations`. For host-owned migrations, set
`nvl-ownership.migrations.enabled=false` and run
`php artisan vendor:publish --tag=nvl-ownership-migrations`. Never run both modes. The
protocol migration must run on the coordinator and each participating physical
source before adoption. Keep fixed protocol identities and store UUIDs intact.
Publishing migrations makes them application migrations even when the flag is false.

Review [UPGRADING.md](UPGRADING.md) before switching an existing application.
An authorized maintenance operation injects
`Nvl\Ownership\Services\OwnershipAdoptionCoordinator`, supplies reviewed
`Nvl\Ownership\ValueObjects\OwnershipMapping` records and an
`Nvl\Ownership\ValueObjects\OwnershipOperation`, and invokes `prepare()`, bounded
`backfill()` until it returns true, `verify()`, then `activate()`.
Bind `Nvl\Ownership\Contracts\OwnershipAdoptionAccess`; the default denies.
Actual application maintenance, source locks and durable audit are mandatory.
Use `OwnerIdentityResolver::reference()` to obtain full canonical native owner
references; do not construct storage identities from request values.

Keep `nvl-ownership.enabled=false` during expansion and draining. Under maintenance,
configure the final graph and set it to true before calling the coordinator; the
prepared graph stays blocked until activation. On each physical source (including
the coordinator), run the chosen migration mode using the appropriate connection:

```sh
# In a maintenance process configured with nvl-ownership.connection=ownership_coordinator:
php artisan migrate --database=ownership_coordinator --path=vendor/nvl/ownership/database/migrations/ownership
# In a separate maintenance process configured with nvl-ownership.connection=media_source:
php artisan migrate --database=media_source --path=vendor/nvl/ownership/database/migrations/ownership
```

These protocol-only paths apply to vendor-loaded mode. In published mode, select
only the exact published Ownership migration with `--path=database/migrations/<published-ownership-file>.php`;
do not also load the vendor copy. Run domain/host migrations separately on their
intended sources. Each process must resolve `nvl-ownership.connection` to the same
physical source as its command; the migration explicitly uses that configuration.

Set `nvl-ownership.connection` to the coordinator connection, `nvl-media.connection`
to Media's source, and retain the native Company/actor models' connection names.
The protocol must also exist on native owner/actor sources. A coordinator connection
does not move package records. Do not run duplicate migrations when these names
resolve to the same physical database.

A maintenance command can use this exact API (inject the services, authorize the
operation, and persist the returned run ID before continuing):

```php
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\ValueObjects\OwnershipMapping;
use Nvl\Ownership\ValueObjects\OwnershipOperation;

$operation = new OwnershipOperation('media-cutover', 'operator', 'reviewer-123');
$reference = $ownerIdentities->reference($company); // OwnerIdentityResolver
$mappings = [
    new OwnershipMapping('media.assets', $assetId, $reference,
        metadata: ['expected_digest' => $reviewedSha256], legacyTenantId: $originalTenantUuid),
    // Explicitly list EVERY retained row of every resource below.
];
$plan = $coordinator->prepare(['media'], $mappings, $operation);
$runId = $plan->id; // Store durably; resume via $coordinator->resume($runId).
while (! $coordinator->backfill($plan, 100, $operation)) {
    // Persist/report bounded progress in this maintenance command.
}
$verification = $coordinator->verify($plan);
if (! $verification->passed()) {
    throw new RuntimeException(implode('; ', $verification->errors));
}
$coordinator->activate($plan, $operation);
```

`OwnerIdentityResolver` is `Nvl\Ownership\Services\OwnerIdentityResolver`; its
`reference(Illuminate\Database\Eloquent\Model $owner): OwnerReference` re-reads
canonical native identity. Host `OwnershipAdoptionAccess::authorize(OwnershipOperation
$operation): void` must check the real maintenance operator; the strings above are
audit attribution, not authorization. `resume($runId)` restores immutable input;
continue the same bounded backfill/verify/activate calls after interruption.

| Media resource key | Ownership |
|---|---|
| `media.assets` | Configured native column or concrete pivot |
| `media.associations` | Inherits canonical `media` |
| `media.variations` | Inherits canonical `media` |
| `media.translations` | Inherits canonical `media` |
| `media.multipart` | Captured |
| `media.slot-operations` | Captured |
| `media.catalog-grants` | Captured recipient |

For installations with no legacy tenancy, omit `legacyTenantId` or pass null;
never invent a tenant UUID. For each existing asset and variation, `expected_digest`
is the lowercase SHA-256 of its actual binary object (stream the entire object with
SHA-256 before reviewing the mapping). It is required on those binary mappings,
including soft-deleted retained records; other Media mappings accept no metadata.
The adapter rechecks the bytes, size and canonical asset digest before activation.

Every existing Media root and retained child/receipt requires an explicit mapping.
A legacy mapping also names its original tenant UUID. Shared/ambiguous rows must
be split and verified before assigning exclusive ownership; no default owner is
inferred. Empty tables still participate in the immutable graph. Partial phases
stay unreadable. Multi-source activation uses source stamps and a committed
coordinator barrier, not a distributed database transaction.

## Queues, system and central work

Mutable jobs may implement
`Nvl\Support\Ownership\Contracts\OwnershipQueuedJob` and use
`Nvl\Support\Ownership\Traits\CapturesOwnership`, calling `captureOwnership()`
in their constructor. Override `ownershipUniqueIdentity()` for business uniqueness.
Readonly facts initialize an `OwnershipJobEnvelope` directly in the constructor.
Native publication validates the scalar capture; workers re-admit the current
actor, owner, generation and schema before restoring models or executing callbacks.
Retry captured failures with `php artisan nvl:queue:retry <failed-job-id>`; native
`queue:retry` restores commands before admission and is blocked for captured NVL
work, including ordinary business failures. Raw retry preserves the producer
envelope, and the worker re-admits it. Never infer an owner from ambient worker state. Unresolved owned work is rejected.

`runSystem()` and `central()` require explicit operation allowlists, host access
policies and durable audits. They are not broad ownership bypasses. Media central
catalogs additionally require the configured subclass's `allowsCentralCatalog()`
opt-in and a default-deny `Nvl\Media\Contracts\MediaCatalogAccess` host binding.
`Nvl\Media\Ownership\MediaOwnerCatalog` issues grants in the admitted recipient,
rechecks revision/revocation during copying, and creates independent private assets.

## Development and verification

Run the affected package suite and max-level analysis. The source workbench uses
`php tools/run-package-tests.php --concurrency=1 ownership media` and
`php tools/analyse-packages.php core ownership media`. In a package checkout use
`composer test` and `composer analyse`. Run `php artisan nvl:ownership:doctor --json`
before enabling service. Rehearse backups, exact mappings, interruption/resumption,
negative cross-owner operations, private delivery and real worker restoration on
the deployment's actual database and queue transports.

## Verified public owner sites

Public packages use `OwnerSiteResolver`, `PublicOwnerSiteRunner` and
`ResolvePublicOwnerSite` with Core's immutable `VerifiedOwnerSiteContext`.
Bind a host resolver backed by enabled persisted origin mappings; unknown sites
fail closed. Only the resolved full native owner, exact canonical request origin,
site and declared resource graph are admitted. `resources` is a nonempty list of
read capabilities and `writableResources` is its explicit write subset. Locale,
content scope and site remain distinct from the owner reference. Public requests
cannot mint actor, system, central, catalog-management or queued authority.

Outside HTTP, use `PublicOwnerSiteRunner::run(Request::create($canonicalOrigin),
$callback)` to revalidate the same mapping. State restores on normal return and
failure; nested public work must keep exactly the same verified site/capabilities.
Public Form callbacks revalidate that identity and generation after native commit.
Use separately authorized system workflows for durable background work.

Owned root instance counters, quiet counters, saves, deletes and restorations
retain the canonical source guard. Configured subclasses cannot replace those
persistence seams. Models and query builders remain native Eloquent APIs; use domain services for their complete lifecycle and authorization guarantees. Ownership is not a replacement for host authorization or native SQL permissions.


## Reviewed migration commands

Create a JSON input with `packages` and `mappings`. Each mapping has `resource`,
`record_id`, an explicit full `owner` reference (or an explicitly supported central
`null`), optional `legacy_tenant_id`, and adapter-specific `metadata`.
No owner is inferred from a tenant list, first user, environment or CLI actor.
Install protocol migrations on every participating source before planning.

```sh
php artisan nvl:ownership:plan --input=mappings.json > reviewed-plan.json
php artisan nvl:ownership:prepare --plan=REVIEWED_UUID --input=reviewed-plan.json --actor-type=operator --actor-id=DEPLOYMENT_ACTOR
php artisan nvl:ownership:backfill --plan=REVIEWED_UUID --limit=500 --actor-type=operator --actor-id=DEPLOYMENT_ACTOR
php artisan nvl:ownership:verify --plan=REVIEWED_UUID
php artisan nvl:ownership:activate --plan=REVIEWED_UUID --actor-type=operator --actor-id=DEPLOYMENT_ACTOR
```

`plan` and `verify` do not change schema, rows, checkpoints or activation state.
Review and preserve the generated document; replace the placeholders with its UUID
and your authenticated deployment actor's audit identity. Inputs are bounded JSON
files (sixteen MiB), never executable PHP. Repeat `backfill` until its JSON reports
`done: true`. An interrupted prepare resumes through `backfill`, using the same
persisted assignments. A UUID cannot be prepared a second time with new mappings.

Every mutation still requires real maintenance and fresh `OwnershipAdoptionAccess`.
The host adapter must authenticate the actual operator independently of these CLI
strings and verify stopped producers, drained requests/queues and stopped workers
before cutover. The default denies. The operation purposes are
`ownership.prepare`, `ownership.backfill`, and `ownership.activate`. Verification
alone grants no permission to activate. Activation rechecks the graph, current
owners, mappings and physical constraints under the actual source leases. It never
drops historical columns, tables, IDs, provider keys or private files.

Native translation copy uses `TranslationOverrideRepository` and
`ExportTranslationOverridesContract`, with `nvl-translations.models.override` and
an exact `nvl-translations.ownership.keys` allowlist. The source language catalog
remains a global editing workspace. Owner-scoped copy never rewrites source files.

## Testing your app

Bind a host `OwnerAccess` policy and use persisted native owners/actors with the
configured subclasses. Exercise your real public workflow inside an injected
`OwnershipRunner`; test allowed and cross-owner denial, immutable/quiet writes,
canonical inherited relations and transaction rollback. Use Laravel container
substitutions only for host orchestration tests. Ownership provides no universal
fake or domain model factory. Native queue restoration, private storage and
runtime-removal denial require the real deployment adapters; the source rehearsal
checkpoint does not certify an arbitrary host configuration.

Ownership declares no domain events; see [event behavior](docs/events.md). Protocol
state is not an application outbox and Laravel database/queue lifecycle hooks are
not public domain facts. Keep actual worker tests distinct from `Queue::fake()`.

## License

MIT. See [LICENSE](LICENSE).

## License

MIT. See [LICENSE](LICENSE), [CHANGELOG.md](CHANGELOG.md),
[CONTRIBUTING.md](CONTRIBUTING.md), and [SECURITY.md](SECURITY.md).
