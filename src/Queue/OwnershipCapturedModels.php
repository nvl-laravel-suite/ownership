<?php

declare(strict_types=1);

namespace Nvl\Ownership\Queue;

use Illuminate\Container\Container;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Jobs\Job;
use Nvl\Support\Ownership\Contracts\OwnershipBoundary;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use SplObjectStorage;

/**
 * Validates supported restored model captures without retaining a request or job graph.
 *
 * @internal
 */
final readonly class OwnershipCapturedModels
{
    /** Resolve current canonical resource boundaries. */
    public function __construct(private Container $container) {}

    /** Inspect each object once with a finite recursion bound. */
    public function validate(mixed $value): void
    {
        $this->inspect($value, new SplObjectStorage, 0);
    }

    /** @param SplObjectStorage<object, mixed> $seen */
    private function inspect(mixed $value, SplObjectStorage $seen, int $depth): void
    {
        if ($depth > 64) {
            throw new OwnershipViolation('The captured command graph exceeds the supported depth.');
        }
        if (is_object($value)) {
            if ($value instanceof Job) {
                return;
            }
            if ($seen->contains($value)) {
                return;
            }
            $seen->attach($value);
            if ($value instanceof Model) {
                $resource = $this->container->make(OwnershipResourceRegistry::class)->forModel($value);
                $this->container->make(OwnershipBoundary::class)->assertRecord($value, $resource->key);

                return;
            }
            $value = (array) $value;
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->inspect($item, $seen, $depth + 1);
            }
        }
    }
}
