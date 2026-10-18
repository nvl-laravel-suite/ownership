# Upgrading to ownership

This unreleased candidate covers Core, Settings, Activity, Translatable and Media.
Do not replace the legacy authority for packages whose ownership integration has
not been completed. Keep production disabled until the exact deployment graph and
backend have been rehearsed.

1. Deploy the Core 5.1 bridge and converted packages before cutover. Core remains
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
