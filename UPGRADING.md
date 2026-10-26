# Upgrading to ownership

## NVL Light: planned family 7

Light requires PHP 8.4 and Laravel 13. Replace retired package Actions, transport DTOs, HTTP endpoints and generated frontend types with host-owned adapters around the native services documented in [README.md](README.md). Models remain ordinary Eloquent models; domain operations retain their documented policies, validation, locks and lifecycle guarantees. Pass explicit admitted actors and configure optional integrations deliberately.

Upgrade the selected family together. Preserve stored identities, migration ownership, source connections, queue state and operation recovery journals. Review and apply the relevant storage/adoption procedure before accepting writes; installing new code alone does not migrate existing data.

## Historical upgrade notes

The following sections describe pre-Light releases and their old APIs, compatibility windows and commands. They remain useful for understanding existing installations, but are not Light setup instructions. The old Tenancy bridge, DTO/Action contracts, package HTTP and type generation are retired in Light.

## 6.0.1 consolidation patch

The shared native backfill implementation first ships in Ownership 6.0.1. Domain patches using it reject an older installed Ownership runtime. Ownership remains optional; ordinary unowned hosts do not need to install it.

This patch introduces no schema migration or data conversion. Keep existing model subclasses, ownership declarations, adoption cursors and queued payloads. Existing source transactions, immutable owner checks and legacy exception contracts are retained. Deploy with the normal configuration-cache rebuild and worker restart; a completed adoption does not need to be repeated.

## Major 6 ownership adoption

The published 6.0.0 release includes native integrations across the NVL stateful
package graph. Tenancy is an explicit temporary bridge for existing installations. Keep production disabled until the exact deployment graph and
backend have been rehearsed.

1. Deploy the Core 6 bridge and converted packages before cutover. Core remains
   installed and rejects prepared/adopted storage when Ownership is removed or
   disabled. Old binaries predating that bridge cannot safely serve adopted data.
2. Back up every physical source and preserve its durable store UUID. Review model
   subclasses, native morph aliases, connection/table identities, owner and actor
   registrations, host access/status policies, concrete foreign keys and pivots.
3. Choose vendor-loaded or published protocol migrations, never both. Install the
   protocol on every participating source. Domain base migrations and host native
   owner/pivot migrations precede fenced package adoption.
4. Drain requests, queue/after-response work and workers; enter actual application
   maintenance. Supply durable authorized adoption access and an immutable reviewed
   mapping for each existing record. Preserve each original tenant ID. Explicitly
   resolve shared, orphaned and ambiguous records; no first-user/global fallback.
5. Configure the exact owned model graph, prepare, backfill in bounded resumable
   pages, verify, and activate through OwnershipAdoptionCoordinator. Partial source
   preparation and activation failures remain blocked. Resume the recorded plan;
   do not edit mappings/configuration or invent readiness markers mid-run.
6. Verify negative cross-owner reads/writes, quiet saves, translations, storage,
   private capabilities and native worker restoration. Rebuild config caches and
   restart all processes before leaving maintenance.

Media preserves the native asset UUID and exclusive native Company FK or pivot.
Associations, variations and translations inherit the canonical asset; multipart
and slot receipts retain captured ownership. Physical object moves/copies require
verified checksums and rollback-aware cleanup. Central source rows are a separate
explicit capability and cannot be assigned as a default disposition.

Disabling a flag or uninstalling a runtime is not rollback: durable preparation
markers, constraints and source identity continue to deny access. Restore a
coordinated pre-adoption backup with the compatible bridge binaries, or use a
separately reviewed retirement/transfer procedure. Protocol migration `down()` is
intentionally prohibited. Never delete markers, drop guards or rename protocol
tables to regain access.


The migration command sequence and mapping JSON are documented in README.md.
Keep the reviewed UUID through all phases. Preparing an existing UUID is rejected;
resume interrupted preparation through its bounded backfill command. The source
inventory, actual store IDs, configured models and fingerprints are compared again
under the coordinator leases before preparation. Historical Auth pending proofs
must drain or expire; they are never promoted into a new native authentication
proof. Billing and Payments retain existing provider correlation and idempotency
keys. Original translation overrides remain in their old table while exact native
copies preserve their IDs and revisions.

The ownership replacement is prepared as the coordinated 6.0.0 release. The explicit
Tenancy bridge and deprecated tenant-specific entry points have a full migration
window; removal is no earlier than major 7, after separately reviewed retention
and retirement migrations. Prepared source constraints do not establish publication. The final merged commit
requires exact-source release evidence, then mirror/Packagist and public install verification.
