<?php

declare(strict_types=1);

namespace Nvl\Ownership\Queue;

use Closure;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\CallQueuedHandler;
use Nvl\Ownership\Services\OwnershipQueueCommand;
use Nvl\Ownership\Services\OwnershipQueueContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Contracts\OwnershipQueueHandler;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCarrier;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;

/**
 * Restores the boundary before Laravel deserializes either execution or failure commands.
 *
 * @internal
 */
class OwnershipCallQueuedHandler extends CallQueuedHandler implements OwnershipQueueHandler
{
    /** Identify a handler that explicitly implements both metadata admission boundaries. */
    public static function compatible(mixed $handler): bool
    {
        return $handler instanceof OwnershipQueueHandler;
    }

    /**
     * Admit the captured native payload without restoring execution or failure commands.
     *
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data): void
    {
        $this->withinPayload($this->legacyPayload($data), static fn (): null => null);
    }

    /** @param array<string, mixed> $data */
    public function call(Job $job, array $data): void
    {
        $data = $this->legacyPayload($data);
        $this->withinPayload($data, fn () => parent::call($job, $data));
    }

    /** @param array<string, mixed> $data */
    public function failed(array $data, mixed $e, string $uuid, ?Job $job = null): void
    {
        $data = $this->legacyPayload($data);
        $this->withinPayload($data, fn () => parent::failed($data, $e, $uuid, $job));
    }

    /** Validate the carried command boundary before native middleware, handle or failed callbacks.
     * @param  array<string, mixed>  $data
     */
    protected function getCommand(array $data): mixed
    {
        if (! is_array($data['nvl_ownership'] ?? null)) {
            throw new OwnershipViolation('The queued command requires owner envelope metadata.');
        }
        $command = parent::getCommand($data);
        $envelope = OwnershipJobEnvelope::fromArray($data['nvl_ownership']);
        if ($envelope->context()->mode !== OwnershipContextMode::Disabled
            && (! is_object($command)
                || $this->container->make(OwnershipQueueCarrier::class)->envelope($command)->toArray() !== $envelope->toArray())) {
            throw new OwnershipViolation('The carried job envelope differs from the worker payload.');
        }

        if ($envelope->context()->mode !== OwnershipContextMode::Disabled) {
            $this->container->make(OwnershipQueueCommand::class)->validateRestored($command);
        }

        return $command;
    }

    /**
     * Admit old object payloads only on disabled workers; run() still checks every adoption marker.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function legacyPayload(array $data): array
    {
        if (array_key_exists('nvl_tenancy', $data)) {
            throw new OwnershipViolation('Legacy captured jobs must be drained before ownership cutover.');
        }
        $owned = array_filter($this->container->make(OwnershipResourceRegistry::class)->all(), static fn ($resource): bool => $resource->ownership->strategy !== 'unscoped');
        $command = $data['commandName'] ?? null;
        $captured = is_string($command) && is_subclass_of($command, OwnershipQueuedJob::class);
        if (! array_key_exists('nvl_ownership', $data) && ! $captured && ($owned === [] || $this->container->make(Repository::class)->get('nvl-ownership.enabled') !== true)) {
            $data['nvl_ownership'] = OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled))->toArray();
        }

        return $data;
    }

    /** @param array<string, mixed> $data */
    private function withinPayload(array $data, Closure $operation): mixed
    {
        if (! is_array($data['nvl_ownership'] ?? null)) {
            throw new OwnershipViolation('Queued work requires scalar owner envelope metadata.');
        }
        $envelope = OwnershipJobEnvelope::fromArray($data['nvl_ownership']);
        if (! is_string($data['commandName'] ?? null) || ! is_string($data['command'] ?? null)) {
            throw new OwnershipViolation('The queued command representation is invalid.');
        }

        return $this->container->make(OwnershipQueueContext::class)->run($envelope, function () use ($data, $envelope, $operation): mixed {
            $this->container->make(OwnershipQueueCommand::class)->validate($data, $envelope);

            return $operation();
        });
    }
}
