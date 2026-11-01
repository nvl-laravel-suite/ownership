# Changelog

## [Unreleased]

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
