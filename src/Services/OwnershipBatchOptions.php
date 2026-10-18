<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

/** Applies canonical inert inspection before native batch callback restoration. @internal */
final readonly class OwnershipBatchOptions implements \Nvl\Support\Ownership\Contracts\OwnershipBatchOptions
{
    /** Retain only stateless raw command inspection. */
    public function __construct(private OwnershipQueueCommand $commands) {}

    /** Preserve native signed callback validation after this admission check. */
    public function validate(string $serialized): void
    {
        $this->commands->batchEnvelope($serialized);
    }
}
