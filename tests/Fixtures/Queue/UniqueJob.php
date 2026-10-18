<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Nvl\Support\Ownership\Traits\CapturesOwnership;

/** Uses Laravel's real producer lock with a fixed business identity in two owners. */
final class UniqueJob extends ProbeJob implements ShouldBeUnique
{
    use CapturesOwnership;
    use Dispatchable;
    use Queueable;

    public int $uniqueFor = 60;

    /** Capture once before PendingDispatch can acquire native uniqueness. */
    public function __construct(string $label = 'same-native-id')
    {
        parent::__construct($label);
        $this->captureOwnership();
    }

    /** Retain the business key independently of the captured owner partition. */
    protected function ownershipUniqueIdentity(): string
    {
        return $this->label;
    }
}
