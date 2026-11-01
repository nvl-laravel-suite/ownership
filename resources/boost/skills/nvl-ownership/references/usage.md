# Native Ownership recipes

Read [installation](installation.md) for discovery and migration switches and [API](api.md) for full contracts. The host owns its owner model, business resource models, schema, membership/central/system policies, verified origin mappings and adoption decisions. This runtime is optional, explicitly enabled and independent of `nvl/auth`.

## Choose the boundary

| Work | Surface | Required decision |
| --- | --- | --- |
| Actor enters an owner | `Nvl\Ownership\Contracts\OwnershipRunner::run` | `OwnerAccess::assertCanEnter(Authenticatable $actor, Model $owner): void` |
| Bounded background work | `OwnershipRunner::runSystem` | Allowlisted operation and `OwnerSystemAccess` |
| Cross-owner/central work | `OwnershipRunner::central` | Allowlisted operation and `CentralAccess` |
| Explicit global work | `OwnershipRunner::unowned` | Does not grant access to owned storage |
| Public site read/write | `Nvl\Ownership\Services\PublicOwnerSiteRunner` | Persisted enabled origin mapping through `OwnerSiteResolver` |
| Host-owned resource creation | `Nvl\Ownership\Services\OwnershipWriter` | Validated business fields and a canonical registered root |
| Package-composed creation | `Nvl\Support\Ownership\Contracts\OwnershipWriter` | Attribute-array callback; distinct from the native assignment callback |
| Existing data cutover | `OwnershipAdoptionCoordinator`, registry and host adapters | Reviewed mappings, maintenance access, drained processes and conservation proof |
| Queue/model restoration | Support `OwnershipQueuedJob`, `CapturesOwnership`, `OwnershipJobEnvelope` plus the enabled runtime | Producer capture and fresh worker re-admission |

## Declare actual model storage

Let `Company` be the host's persisted owner model and `Invoice` a host business model. Use the actual key type and connection in the host migration. A conventional owner-column model is:

```php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\Ownable;
use Nvl\Support\Ownership\Traits\HasOwnership;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

class Invoice extends Model implements Ownable
{
    use HasOwnership;

    protected $fillable = ['number'];

    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::column(Company::class, 'company_id');
    }
}
```

Do not accept `company_id`, `owner_scope_key` or `owner_reference` from request data. The primary owner is immutable after adoption. Other supported declarations are `OwnershipDefinition::pivot($ownerClass, $table, $resourceKey, $ownerKey)`, `inherited($relation)` and `unscoped()`. Pivots require one concrete exclusive primary owner and matching unique/foreign-key constraints. Inherited resources need their exact canonical parent graph; secondary grants do not replace primary ownership. Package operational captured roots are package-controlled.

Register the owner capability in `nvl-core.owners` or through `Nvl\Support\OwnerRegistry`. Register host business resources before the registry freezes:

```php
use App\Models\Invoice;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition(
    key: 'app.invoices',
    family: 'app',
    model: Invoice::class,
    ownership: Invoice::ownershipDefinition(),
));
```

Packages register their own supported resource definitions using selected concrete model roots. Do not shadow them with a different class over the same table. Configure model replacement before provider boot and use host migrations for added fields. Registration does not migrate storage or activate readiness.

## Bind policy and enter the owner

Bind the real `OwnerAccess` adapter in an application provider. For example, delegate to a host-defined Gate ability:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Nvl\Ownership\Contracts\OwnerAccess;

$this->app->bind(OwnerAccess::class, static fn () => new class implements OwnerAccess {
    public function assertCanEnter(Authenticatable $actor, Model $owner): void
    {
        Gate::forUser($actor)->authorize('enter-owner', $owner);
    }
});
```

Define `enter-owner` using current host membership. The adapter must throw on denial; the default adapter denies. Decide owner availability through `OwnerAvailability`. Authenticate the supplied actor before admission; audit strings are not credentials.

After protocol storage, resource constraints and readiness/adoption are verified, a complete host workflow can create a resource:

```php
use App\Models\Invoice;
use Nvl\Ownership\Contracts\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;

$invoice = app(OwnershipRunner::class)->run($company, $actor, function () use ($invoiceNumber): Invoice {
    return app(OwnershipWriter::class)->create(
        Invoice::class,
        function (OwnershipAssignment $assignment) use ($invoiceNumber): Invoice {
            $invoice = new Invoice(['number' => $invoiceNumber]);
            $invoice->forceFill($assignment->attributes())->saveOrFail();

            return $invoice;
        },
    );
});
```

`$company` and `$actor` are authenticated/canonical host identities and `$invoiceNumber` was validated by the host operation. Use package services for package-owned mutations. The native writer joins its actual model connection transaction and returns the persisted model. Core's neutral writer callback instead receives an attribute array; do not type it as `OwnershipAssignment`.

Runner callbacks restore prior state after success, nested calls and exceptions. Capturing a context object or setting submitted owner fields does not create authority. Ordinary `HasOwnership` model queries, instance writes, quiet mutations and restoration retain canonical enforcement; raw database writes are not a supported isolation shortcut.

## System, central and public workflows

Configure only intended operation strings in `nvl-ownership.system_operations` and `central_operations`; bind `OwnerSystemAccess` and `CentralAccess` to actual host rules. `runSystem($ownerReference, $operation, $callback)` and `central($operation, $actor, $callback)` durably record the admitted operation. They do not share the actor-entry policy or infer authority from `SystemActor` labels.

Bind `OwnerSiteResolver::resolve(string $origin): ?VerifiedOwnerSiteContext` from persisted enabled host mappings. Return the exact `OwnerReference`, stable site ID, canonical origin, registered resource keys and optional writable subset. Then use `PublicOwnerSiteRunner::run($origin, $callback)`. The runner canonicalizes a complete HTTP(S) origin, rechecks site/owner/readiness and validates the frozen resource graph. A writable public resource must be owned and explicitly admitted. Unknown, disabled, mismatched and stale mappings deny before callback entry; nested admission cannot switch sites/capabilities. Do not call it with an HTTP Request or convert public admission into actor/system queue authority.

## Queue capture and recovery

A mutable queued command implements `Nvl\Support\Ownership\Contracts\OwnershipQueuedJob` and uses `Nvl\Support\Ownership\Traits\CapturesOwnership`; call `$this->captureOwnership()` in its producer constructor before dispatch/unique-lock/batch scheduling. Its final `ownershipJobEnvelope()` supplies the captured wire facts. For readonly facts, store `OwnershipJobEnvelope::capture($context)` explicitly and expose the interface method. Do not capture during serialization or infer the worker's current owner.

Enabled native queue hooks re-admit the original principal/owner/generation before model restoration. Preserve payload, attempt/deadline and durable producer identity during retry. Review `nvl:queue:retry --help` for quarantined work; plain queue retry does not remove an ownership denial. Restore locale/context participants and lock partitions after exceptions. Public site capabilities cannot be promoted into queued privileged identities.

## Reviewed adoption

Bind `OwnershipAdoptionAccess` to actual maintenance/operator checks; actor-type/id options record facts, not authenticated permission. Register `OwnershipAdoptionAdapter` implementations for host resources; each adapter owns idempotent prepare/backfill/verify/activate behavior on its source. Drain producers, requests and workers before cutover. Decide every central/orphan/shared mapping explicitly.

The programmatic sequence is `prepare($packages, $mappings, $operation, $review)`, bounded `backfill($plan, $limit, $operation)` until complete, `verify($plan)`, then `activate($plan, $operation)`. Mappings are `OwnershipMapping` values with exact resource/record IDs and reviewed owner references. Persist and `resume($runId)` after interruption. CLI plan/prepare/backfill/verify/activate names and options are in [installation](installation.md) and each command's installed help. Activation requires real constraint, store/generation, maintenance-fence and conservation evidence; never create readiness markers manually. Disabling/removing the runtime does not reverse an adoption.

## Verify the integration

Use `nvl:ownership:doctor --help` and focused consumer tests for owner A/B isolation, denied actor/site/system entry, stale/deleted identities, native string/integer keys, dirty primary ownership, inherited graphs, nested exception restoration and worker replay. Test source outer commit/rollback and physical absence of the optional enforcing runtime. A successful migration or doctor is not a production worker/concurrency certification.
