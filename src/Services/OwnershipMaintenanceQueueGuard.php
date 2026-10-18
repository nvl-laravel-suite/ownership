<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Container\Container;
use Illuminate\Queue\Queue;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/** Fences maintenance publication without retaining an application in static hooks. @internal */
abstract class OwnershipMaintenanceQueueGuard extends Queue
{
    /** Install one named callback while preserving host hooks. */
    public static function register(): void
    {
        $callback = [self::class, 'payload'];
        if (! in_array($callback, self::$createPayloadCallbacks, true)) {
            self::createPayloadUsing($callback);
        }
    }

    /**
     * Reject scheduling before command serialization or after-commit deferral.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function payload(?string $connection = null, ?string $queue = null, array $payload = []): array
    {
        $app = Container::getInstance();
        if (! $app->bound('nvl.ownership.runtime') || $app->make('config')->get('nvl-ownership.enabled') !== true) {
            return [];
        }
        $app->make(OwnershipAdoptionScope::class)->assertQueueAllowed();
        foreach ($app->make(OwnershipResourceRegistry::class)->all() as $resource) {
            if ($resource->ownership->strategy !== 'unscoped') {
                throw new OwnershipViolation('Owned queue publication requires the ownership envelope integration.');
            }
        }

        return [];
    }
}
