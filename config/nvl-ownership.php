<?php

declare(strict_types=1);

/** Ownership is explicit, optional, and independent from schema registration. */
return [
    'enabled' => false,
    'connection' => null,
    'migrations' => ['enabled' => false],
    'system_operations' => [],
    'central_operations' => [],
    // Exact classes of queued commands that cannot implement OwnershipQueuedJob; they run with no owner authority.
    'queue' => ['unowned_jobs' => []],
];
