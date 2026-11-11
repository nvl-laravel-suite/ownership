# Changelog

## [Unreleased]

### Changed

- Verify installation generation and owner availability once per admitted context and connection, and schema readiness once per resource, instead of before every owned operation. Each owned query, attribute set and namespaced key repeated about twelve catalog, marker and owner reads; a second identical query in the same request now runs one statement, and the first query on a further resource repeats only that resource's schema reads. The result lives in a scoped service, so it never outlives a request or job, and it is discarded by every coordinated adoption mutation, a new owner or central context and a reconnected connection. Drift caused by another process (a dropped constraint, a changed generation, an owner made unavailable) is refused from the next context, request or job rather than the next query. Record-level checks on save, refresh and delete are unchanged.

### Fixed

- Require Core 7.0.6, which keeps builder-level `forceDelete()`, `updateOrInsert()` and `updateFrom()` inside the admitted owner. Earlier Core versions ran these on the base query without the ownership scope, so they could reach another owner's rows. Custom Eloquent builders on owned models must use Core's `ScopesOwnedBuilderWrites` trait.
- Make an installed but disabled runtime capture unowned work and native batches exactly like an absent one. `composer require nvl/ownership` with `nvl-ownership.enabled` false made `captureUnowned()` throw "Ownership execution is disabled" and `captureBatch()` demand a guarded repository, breaking packages that schedule unowned work such as Auth deliveries.
- Leave `nvl:queue:retry` to Core, which now registers it on every host, so quarantined records can be raw-retried while this runtime is disabled or absent. `Nvl\Ownership\Console\Commands\QueueQuarantineRetryCommand` is removed; the command name, arguments and output are unchanged. Ordinary failed work with a disabled envelope can use native `queue:retry` while the runtime is disabled.
- Let hosts declare queued commands that cannot implement `OwnershipQueuedJob`. With the runtime enabled and any owned resource, every uncaptured command was refused at dispatch, so `BroadcastEvent`, queued closures, Scout jobs and uncaptured notification, mail and listener wrappers could not be queued at all. Exact command classes listed in the new `nvl-ownership.queue.unowned_jobs` (default empty) are stamped with the explicit unowned envelope and run with no actor, owner or central authority; every other uncaptured class, any owned model they carry and any payload claiming authority stay refused.
- Allow a later deployment to adopt a new child resource of an already active parent. Naming the child's package pulled the parent's package into the run and `prepare()` refused with "marker replacement is forbidden", leaving every owned admission closed with no way forward. A parent package that was not named and whose resources are all active now stays outside the run; its generation is untouched and still verified. Adding a resource to an adapter that already has an active run remains refused and is documented.
- Recognize host foreign keys to a schema-qualified PostgreSQL parent table, and resolve schema-qualified tables on a prefixed connection in trigger and index lookups. A model with `$table = 'tenant.documents'` as a canonical parent could never become ready, and a prefixed connection looked up `prefix_schema.table`.
- Include the connection's table prefix in the names of newly created partition foreign keys and partition unique keys. Two prefixes in one database collided on the foreign key name on MySQL and MariaDB and on the unique index name on PostgreSQL. Names on unprefixed connections, and constraints that already exist, are unchanged.
- Replace SQLite triggers when constraints are applied again. They were created with `IF NOT EXISTS`, so `refreshConstraints()` left a changed or tampered trigger body in place on SQLite.
- Validate reviewed mappings once before and once after each adapter callback during adoption instead of twice around every callback and twice at ingestion. For 300 mappings and two adapters, prepare, backfill, verify and activate drop from 1800, 2700, 1500 and 4200 mapping validations to 900, 1500, 900 and 2400. Every refusal, including drift introduced by a callback, is unchanged.
- Say why a partially selected owned model cannot be saved, refreshed or deleted. A model loaded without its primary key or a protected ownership field was refused with "no longer represents the persisted primary ownership facts" or a bare ownership violation; the refusal now names the missing field.
- Contribute `ownership.readiness` and `ownership.builders` to Core's combined `nvl:doctor`, report an owned model whose builder cannot be constructed as a failed check instead of aborting, and label failing warnings `[WARNING]` rather than `[ERROR]` in `nvl:ownership:doctor` text output.
- Recognize an existing partition foreign key on a connection with a table prefix. A second `constrain()` on MySQL, MariaDB or PostgreSQL added the same constraint name again and failed. Constraint names are unchanged.
- Allow `refreshConstraints()` on an active graph adopted through `FreshHostOwnershipAdoptionAdapter` after it has received records. The helper demanded empty tables during the refresh and left the run fenced in `refreshing`. First adoption still requires empty resources in every phase.
- Resolve the write lease for the commit fence from the current application container, as the SQL and queue hooks do, so the fence observes the lease of the running Octane sandbox.
- Read each owner store identity once per `OwnerIdentityResolver::reference()` or `resolve()` call instead of up to four times. Nothing is retained between calls.
- Report owned models whose custom Eloquent builder does not use `ScopesOwnedBuilderWrites` as an `ownership.builders` doctor warning; it fails `nvl:ownership:doctor --strict`.

## [7.0.4] - 2026-11-11

### Fixed

- Stop reconstructing every owned canonical model four times on each active-generation check, which runs on every owned admission. Connection, fingerprint and marker checks now read one retained attribute-less model per resource. Table, connection, key and morph facts, store identities, markers and committed runs are still read on every check, so configuration and generation drift are refused as before.

<!-- NVL GENERATED CHANGES START -->
### Changed

- reuse canonical models in the active-generation check
<!-- NVL GENERATED CHANGES END -->

## [7.0.3] - 2026-11-10

### Fixed

- Batch disabled captured-job storage admission through Core instead of repeating catalog inspection for every registered resource. Preserve declared-owned refusal, fresh publication checks and exact context restoration. Require Core 7.0.4 or later for the batch primitive.

<!-- NVL GENERATED CHANGES START -->
### Changed

- batch fresh disabled publication storage checks
<!-- NVL GENERATED CHANGES END -->

## [7.0.2] - 2026-11-10

### Fixed

- Reuse owner metadata, resource fingerprints and physical store identities within each fresh adoption validation pass. Retain validation before and after every adapter callback and preserve existing configuration hashes.
- Batch metadata reconstruction for active-generation reads and deduplicate store identity reads during migration review. Cover an empty 154-resource, nine-adapter graph through preparation, backfill, verification and activation.

<!-- NVL GENERATED CHANGES START -->
### Changed

- bound metadata work within adoption validation
<!-- NVL GENERATED CHANGES END -->

## [7.0.1] - 2026-11-09

### Fixed

- Read each participating store identity once per resource fingerprint or graph hash, and refresh those facts on the next operation.

<!-- NVL GENERATED CHANGES START -->
### Changed

- reuse store identity within fingerprint operations
<!-- NVL GENERATED CHANGES END -->

## [7.0.0] - 2026-11-03

- Adopt the reviewed NVL 7 family for PHP 8.4+ and Laravel 13, with compatible required and optional NVL peers. Review the package upgrade guide before deployment.

### Fixed

- Reject padded/newline partition keys, forged morph aliases and case/padding changes to immutable scalar fields while preserving native JSON equality. Compare MariaDB UUID references through their canonical text on the actual source, including MySQL-labelled connections.
- Add explicit authorized constraint refresh with durable conservation checkpoints for active graphs; reject replacement-plan marker conflicts before persisting a new run or mappings. See [UPGRADING.md](UPGRADING.md) for installed-trigger refresh.
- Reuse column and foreign-key metadata within a single live graph inspection while preserving immediate generation, constraint and owner-availability checks between operations.
- Preserve full composite uniqueness on MySQL and MariaDB with ASCII, binary partition keys; refuse unsafe conversion of noncanonical values or columns protected by partition foreign keys.
- Clarify when enabled-only commands become available and why persisted ownership probes cannot retain negative readiness results.

## [6.1.0] - 2026-10-23

<!-- NVL GENERATED CHANGES START -->
### Changed

- expose safe host APIs and harden package boundaries
<!-- NVL GENERATED CHANGES END -->

## [6.0.1] - 2026-10-21

### Changed

- Share bounded native resource backfill across thirteen package adapters. Preserve resource order, resumable cursors, reviewed mappings, source transactions and package-specific transformations and verification.

## [6.0.0] - 2026-10-20

### Release fixes

- Publish configuration, migrations and agent skills through the common namespaced collision boundary.

### Changed

- Introduce optional native owner columns, exclusive concrete pivots and inherited ownership; preserve host keys and authorization. Add fenced, resumable plan/prepare/backfill/verify/activate commands, source identities, transaction leases, queue admission and runtime removal protection across the stateful NVL graph.
- Align required/development NVL peers on `^6.0`, optional peer conflicts on major 6, and `dev-main` on `6.x-dev`. Review [UPGRADING.md](UPGRADING.md) before rollout.
