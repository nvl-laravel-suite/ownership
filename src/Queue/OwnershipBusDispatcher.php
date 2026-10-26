<?php

declare(strict_types=1);

namespace Nvl\Ownership\Queue;

use Closure;
use Illuminate\Bus\Dispatcher;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Services\OwnershipQueueCarrier;

/**
 * Preserves native bus algorithms and host pipes while honoring explicit scheduling capture.
 *
 * @internal
 */
class OwnershipBusDispatcher extends Dispatcher
{
    /** Preserve the exact native dispatcher configuration without replacing host adapters. */
    public static function fromNative(Dispatcher $dispatcher): self
    {
        $guarded = new self($dispatcher->container, $dispatcher->queueResolver);
        $guarded->pipeline = $dispatcher->pipeline;
        $guarded->pipes = $dispatcher->pipes;
        $guarded->handlers = $dispatcher->handlers;
        $guarded->allowsDispatchingAfterResponses = $dispatcher->allowsDispatchingAfterResponses;

        return $guarded;
    }

    public function dispatch(mixed $command): mixed
    {
        return $this->captured($command, fn (): mixed => parent::dispatch($command));
    }

    public function dispatchSync(mixed $command, mixed $handler = null): mixed
    {
        return $this->captured($command, fn (): mixed => parent::dispatchSync($command, $handler));
    }

    public function dispatchNow(mixed $command, mixed $handler = null): mixed
    {
        return $this->captured($command, fn (): mixed => parent::dispatchNow($command, $handler));
    }

    public function dispatchAfterResponse(mixed $command, mixed $handler = null): void
    {
        $this->captured($command, function () use ($command, $handler): void {
            parent::dispatchAfterResponse($command, $handler);
        });
    }

    /** @template T
     * @param  Closure(): T  $operation
     * @return T
     */
    private function captured(mixed $command, Closure $operation): mixed
    {
        $carrier = $this->container->make(OwnershipQueueCarrier::class);
        if (! is_object($command) || ! $carrier->captured($command)) {
            return $operation();
        }
        $envelope = $carrier->envelope($command);

        return $this->container->make(OwnershipQueueContext::class)->run($envelope, function () use ($envelope, $command, $operation): mixed {
            if ($envelope->context()->mode !== OwnershipContextMode::Disabled) {
                $this->container->make(OwnershipCapturedModels::class)->validate($command);
            }

            return $operation();
        });
    }
}
