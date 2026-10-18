---
name: nvl-ownership
description: Use when integrating or reviewing nvl/ownership, native owner columns or concrete pivots, configured NVL model subclasses, ownership adoption, isolation, or captured queue context.
---

# NVL Ownership

Read the installed Ownership README and UPGRADING guide for exact API and rollout
steps. This candidate integrates Core, Settings, Activity, Translatable and Media;
other package conversions remain separate work. NVL Auth/Tenancy are optional.

## Consumer model

The Media base already implements Ownable and HasOwnership. Configure its concrete
subclass before provider boot:

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

Set `nvl-media.models.media` to that class. Register native Company and Laravel
actor classes in `nvl-core.owners`. Supply matching native foreign keys. A concrete
primary pivot uses `OwnershipDefinition::pivot(Company::class, $table,
$mediaForeignKey, $companyForeignKey)` with one assignment per asset; grants express
sharing separately. Preserve native keys and Laravel morph identities.

## Admission and persistence

Bind `Nvl\Ownership\Contracts\OwnerAccess`; its default denies. Inject
`Nvl\Ownership\Contracts\OwnershipRunner` and call `run($company, $actor,
$callback)` before the source transaction. A direct Company Media target needs
HasNvlMedia/InteractsWithNvlMedia and declared slots, not a second ownership model.
Uploader attribution does not authorize Company access.

Media's public upload workflow coordinates its writer automatically. Custom roots
use `Nvl\Ownership\Services\OwnershipWriter::create()` with an
`Nvl\Ownership\ValueObjects\OwnershipAssignment` callback. The internal neutral
Core writer instead supplies an attribute array; do not mix these contracts.
Canonical persisted ownership governs reads, quiet saves, inherited relations and
native worker restoration; loaded relations and submitted ownership are untrusted.

## Adoption and queues

Choose vendor-loaded or published protocol migrations, never both. Under actual
authorized maintenance, drain processes, preserve physical store UUIDs, supply
explicit reviewed mappings, and prepare/backfill/verify/activate through
OwnershipAdoptionCoordinator. Resolve shared/orphaned rows explicitly. Partial
phases remain blocked. Disabling or removing the runtime is not rollback.

Mutable queued work uses OwnershipQueuedJob/CapturesOwnership and constructor
capture. Readonly facts initialize scalar OwnershipJobEnvelope directly in the
constructor. Retain full producer principal, owner and generation; never infer a
worker owner. Enabled runtime requires the transaction-committing capability.
Verify with `nvl:ownership:doctor`, negative isolation tests and actual backend/
worker tests; installation and provider registration do not prove readiness.
