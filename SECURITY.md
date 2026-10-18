# Security Policy

Report vulnerabilities through [private reporting](https://github.com/nvl-laravel-suite/ownership/security/advisories/new).
Do not disclose owner data, credentials, raw queue payloads or unredacted SQL in public issues.

The candidate enabled runtime has native PostgreSQL 17/Laravel 13/PHP 8.4 evidence;
other backend/version profiles require independent verification. Installation does
not activate isolation. Host access policies and reviewed adoption are mandatory.

Core's absence guards, immutable source identities, native constraints, queue
admission and source transaction leases remain active isolation boundaries.
Disabling/removing Ownership must never expose prepared or adopted storage.
See [UPGRADING.md](UPGRADING.md) for cutover, process drain and rollback limits.
