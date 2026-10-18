<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

use Closure;
use Nvl\Support\Ownership\Contracts\OwnershipContextParticipant;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/** Models a retained package service that must restore its primitive scope-local state. */
final class StatefulOwnershipParticipant implements OwnershipContextParticipant
{
    public string $value = 'original';

    /** Restore the exact preceding value after a nested scope or callback failure. */
    public function enter(OwnershipSnapshot $next): Closure
    {
        $previous = $this->value;
        $this->value = $next->owner === null ? $next->mode->value : $next->owner->key;

        return function () use ($previous): void {
            $this->value = $previous;
        };
    }
}
