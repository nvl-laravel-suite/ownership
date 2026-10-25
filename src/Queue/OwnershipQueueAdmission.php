<?php

declare(strict_types=1);

namespace Nvl\Ownership\Queue;

use Illuminate\Container\Container;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\JobName;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Contracts\OwnershipQueueHandler;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\DisabledOwnershipQueueContext;
use Nvl\Support\Ownership\Services\OwnershipQueueQuarantine;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Throwable;

/** Admits raw wire metadata before Laravel restores execution or terminal failure commands. @internal */
final readonly class OwnershipQueueAdmission
{
    /** Resolve only the current worker's services. */
    public function __construct(private Container $container) {}

    /** Reject raw captured work without invoking native job or model restoration. */
    public function handle(JobProcessing $event): void
    {
        if ($this->container->make('config')->get('nvl-ownership.enabled') !== true) {
            return;
        }
        $payload = $event->job->payload();
        $data = $payload['data'] ?? null;
        if (! is_array($data)) {
            return;
        }
        $name = $data['commandName'] ?? null;
        try {
            if (! array_key_exists('nvl_ownership', $data) && ! array_key_exists('nvl_tenancy', $data) && (! is_string($name) || ! is_subclass_of($name, OwnershipQueuedJob::class))) {
                return;
            }
            if (! is_array($data['nvl_ownership'] ?? null) || array_key_exists('nvl_tenancy', $data)) {
                throw new OwnershipViolation('Queued ownership requires one complete scalar envelope.');
            }
            $envelope = OwnershipJobEnvelope::fromArray($data['nvl_ownership']);
            $runtime = $this->container->bound('nvl.ownership.runtime');
            if (! $runtime) {
                if ($envelope->context()->mode !== OwnershipContextMode::Disabled) {
                    throw new OwnershipViolation('Captured ownership requires its enforcing runtime.');
                }
                $this->container->make(DisabledOwnershipQueueContext::class)->run($envelope, static fn (): null => null);

                return;
            }
            $native = $payload['job'] ?? null;
            if (! is_string($native)) {
                throw new OwnershipViolation;
            }
            [$class, $method] = JobName::parse($native);
            if (! is_string($class) || $class === '' || $method !== 'call') {
                throw new OwnershipViolation('Captured ownership requires both guarded native restoration entry points.');
            }
            $handler = $this->container->make($class);
            if (! $handler instanceof OwnershipQueueHandler) {
                throw new OwnershipViolation('The host handler does not implement ownership admission.');
            }
            $named = [];
            foreach ($data as $key => $value) {
                if (! is_string($key)) {
                    throw new OwnershipViolation;
                }
                $named[$key] = $value;
            }
            $handler->validate($named);
        } catch (Throwable $exception) {
            $this->container->make(OwnershipQueueQuarantine::class)->reject($event, $exception);
        }
    }
}
