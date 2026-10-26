<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Bus\BatchRepository;
use Illuminate\Container\Container;
use Illuminate\Queue\CallQueuedHandler;
use Illuminate\Queue\Queue;
use Nvl\Ownership\Queue\OwnershipCallQueuedHandler;
use Nvl\Ownership\Queue\OwnershipDatabaseBatchRepository;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCarrier;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Fences maintenance publication without retaining an application in static hooks.
 *
 * @internal
 */
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
        $owned = array_filter($app->make(OwnershipResourceRegistry::class)->all(), static fn ($resource): bool => $resource->ownership->strategy !== 'unscoped');
        $data = $payload['data'] ?? null;
        $command = is_array($data) ? ($data['command'] ?? null) : null;
        if ($owned === [] && (! is_object($command) || ! $app->make(OwnershipQueueCarrier::class)->captured($command))) {
            return [];
        }
        if (! is_object($command)) {
            throw new OwnershipViolation('Owned queue publication requires an explicitly captured native command.');
        }
        $envelope = $app->make(OwnershipQueueCarrier::class)->envelope($command);
        $app->make(OwnershipQueueContext::class)->run($envelope, function () use ($app, $command, $data, $envelope): void {
            $app->make(OwnershipQueueCommand::class)->validateRestored($command);
            if (! OwnershipCallQueuedHandler::compatible($app->make(CallQueuedHandler::class))) {
                throw new OwnershipViolation('The native handler must preserve ownership admission and both restoration boundaries.');
            }
            if (is_string($data['batchId'] ?? null)) {
                $repository = $app->make(BatchRepository::class);
                if (! $repository instanceof OwnershipDatabaseBatchRepository) {
                    throw new OwnershipViolation('Owned batches require guarded native storage.');
                }
                $batch = $repository->find($data['batchId']);
                if ($batch === null || ($batch->options['nvl_ownership'] ?? null) !== $envelope->toArray()) {
                    throw new OwnershipViolation('Queued work differs from its persisted batch boundary.');
                }
            }
        });

        return ['data' => [...$data, 'nvl_ownership' => $envelope->toArray()]];
    }
}
