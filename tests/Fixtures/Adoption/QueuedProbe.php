<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Illuminate\Contracts\Queue\ShouldQueue;

/** Queue payload that must never escape a maintenance callback. */
final class QueuedProbe implements ShouldQueue
{
    /** No side effect is needed to prove publication fencing. */
    public function handle(): void {}
}
