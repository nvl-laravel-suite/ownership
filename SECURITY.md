# Security Policy

Report vulnerabilities through [private reporting](https://github.com/nvl-laravel-suite/ownership/security/advisories/new).
Do not disclose owner data, credentials, raw queue payloads or unredacted SQL in public issues.

The prepared 6.0.0 enabled runtime has native PostgreSQL 17 and MySQL 8.4
archive-consumer evidence on Laravel 13/PHP 8.4, including Redis, private storage,
worker restoration and disabled/removed-runtime denial. Final-source release
certification and other backend/version profiles require separate verification. Installation does
not activate isolation. Host access policies and reviewed adoption are mandatory.

Core's absence guards, immutable source identities, native constraints, queue
admission and source transaction leases remain active isolation boundaries.
Disabling/removing Ownership must never expose prepared or adopted storage.
See [UPGRADING.md](UPGRADING.md) for cutover, process drain and rollback limits.
