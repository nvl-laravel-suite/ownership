---
name: nvl-ownership
description: Use when integrating, testing or reviewing nvl/ownership, native owner columns/pivots, public owner-site admission, host adoption, maintenance fences or captured queue context.
---

# NVL Light Ownership

Ownership supplies the explicitly enabled enforcing runtime over Core's neutral ports. Target PHP `^8.4` and Laravel `^13.0`. NVL Auth is optional; the deprecated Tenancy bridge supplies no authority or fallback. Consumers own their DTOs, Actions, controllers and HTTP integration.

## Host declaration and admission

Configure canonical model subclasses before provider boot. Implement the model ownership contract/trait and return an OwnershipDefinition for the native owner column, concrete primary pivot or supported inherited strategy. Register owner/actor classes in `nvl-core.owners` and preserve native keys and Laravel morph identities. Matching storage constraints are the host's responsibility; grants are distinct from primary ownership.

Bind OwnerAccess; its default denies. Invoke `OwnershipRunner::run($owner, $actor, $callback)` with a persisted canonical owner and authenticated actor. Explicit `runSystem` and `central` operations require their own configured capabilities; audit strings and null do not authorize execution. The runner contract also supports explicit `unowned($callback)` execution without granting protected-resource access. Restoration after nested operations and exceptions is part of the boundary.

Use supported package services for complete mutations. The public OwnershipWriter accepts an OwnershipAssignment callback; Core's neutral writer supplies an attribute array. Do not mix their contracts. Persisted canonical ownership governs reads, quiet saves and queued model restoration. Submitted owner fields and loaded relations are untrusted.

## Public site operations

Bind `OwnerSiteResolver::resolve(string $origin): ?VerifiedOwnerSiteContext` to persisted enabled host mappings. Call `PublicOwnerSiteRunner::run(string $origin, Closure $callback)` from HTTP adapters, CLI or scheduled discovery. An HTTP Request is not the package API. Host authentication may implement Laravel Authenticatable directly; NVL Auth is not required.

The runner validates/canonicalizes a complete HTTP(S) origin, rechecks the mapped canonical site and fresh owner availability, requires runtime readiness, and verifies declared resources/write capabilities. Public writes require an owned writable resource. Unknown/disabled mappings and missing readiness reject before callback entry. Nested admission cannot switch sites or capabilities, and state restores after read/write exceptions.

The resolver returns `VerifiedOwnerSiteContext(OwnerReference $owner, string $site, string $canonicalOrigin, array $resources, array $writableResources = [])` from trusted storage. Resource lists contain registered resource keys; writable keys must also appear in resources. Admission freezes/validates that resource graph and requires configured integration readiness. Use the doctor and actual adoption proof to establish readiness; do not fabricate markers.

The raw publicSite context entry is internal. Constructing a VerifiedOwnerSiteContext is not a grant; use the origin-based runner and trusted resolver. Never derive authority from caller-selected owner/site/resource IDs.

## Queue, adoption and configuration

Mutable queued work uses OwnershipQueuedJob/CapturesOwnership with constructor capture. Readonly facts capture OwnershipJobEnvelope explicitly. Preserve producer principal, owner and generation; never infer a worker owner. Enabled framework hooks enforce admission before restoration and reject retired Tenancy envelopes, including with retained host handlers. Native `queue:retry` is guarded; repair the boundary and use `nvl:queue:retry` for quarantined work without discarding original payload/attempt/deadline evidence.

Use canonical `nvl-ownership` configuration. Both `enabled` and `migrations.enabled` default false. Disabled installs retain native Laravel bus/batch behavior. Protocol migrations load automatically only when both switches are true; enabled execution with vendor migration loading off supports the separately published migration choice. Publish `nvl-ownership-config` and `nvl-ownership-skills`; choose vendor-loaded or published protocol migrations, never both. The host owns native owner-column/pivot migrations and policies.

For authorized adoption, drain processes and use explicit reviewed mappings through plan/prepare/backfill/verify/activate. Preserve store UUIDs, maintenance fences, interruption checkpoints and conservation evidence. Resolve shared/orphaned rows explicitly; partial phases stay blocked. Disabling/removing the runtime does not undo adoption. Use `nvl:ownership:doctor`; it is separate from production worker/database certification.
