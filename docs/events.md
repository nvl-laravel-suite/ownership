# NVL Ownership events

Ownership declares no domain events. The authoritative machine-readable schema is
[event-catalog.json](../resources/event-catalog.json), catalog version `1`.
Admission, transaction-committing and queue lifecycle listeners enforce isolation;
they are not domain notifications or durable delivery promises.

Core committed-event regression tests cover the domain packages that publish
facts; Ownership's own tests cover admission, source leases, adoption and worker
restoration. Do not invent an Ownership event or use a private protocol table as
an application integration outbox.

## Consumer event assertions

Use canonical domain event classes with Laravel `Event::fake([...])` only when
testing the domain publisher or host orchestration. Faking all database/queue
lifecycle events bypasses real enforcement and cannot prove isolation. Run actual
native workers for restoration and quarantine checks. The [upgrade guide](../UPGRADING.md)
describes process drain and reviewed activation.
