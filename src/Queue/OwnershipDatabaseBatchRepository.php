<?php

declare(strict_types=1);

namespace Nvl\Ownership\Queue;

use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Container\Container;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueueContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCarrier;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use ReflectionProperty;

/** Preserves native batch algorithms while validating callback ownership before deserialization. @internal */
class OwnershipDatabaseBatchRepository extends OwnershipBatchRepository
{
    /** Adapt only the exact native implementation, retaining its effective storage and factory. */
    public static function fromNative(DatabaseBatchRepository $repository): self
    {
        $factory = (new ReflectionProperty(DatabaseBatchRepository::class, 'factory'))->getValue($repository);
        $table = (new ReflectionProperty(DatabaseBatchRepository::class, 'table'))->getValue($repository);
        if (! $factory instanceof BatchFactory || ! is_string($table)) {
            throw new OwnershipConfigurationInvalid('The native batch repository configuration is incompatible.');
        }

        return new self($factory, $repository->getConnection(), $table);
    }

    /** Recognize a host implementation that preserves the guarded native batch methods. */
    public static function compatible(mixed $repository): bool
    {
        return $repository instanceof self;
    }

    /** Validate the complete producer batch before persisting callbacks or publishing work. */
    public function store(PendingBatch $batch): Batch
    {
        $app = Container::getInstance();
        if ($app->make('config')->get('nvl-ownership.enabled') === true) {
            $context = $app->make(OwnershipContext::class);
            $wire = $batch->options['nvl_ownership'] ?? null;
            $unowned = is_array($wire) && ($wire['version'] ?? null) === 2;
            if ($unowned && $context->snapshot()->mode !== OwnershipContextMode::Unresolved) {
                throw new OwnershipViolation('Explicit global batches cannot borrow owner or central authority.');
            }
            $expected = ($unowned ? $app->make(OwnershipQueueContext::class)->captureUnowned() : OwnershipJobEnvelope::capture($context))->toArray();
            if (($batch->options['nvl_ownership'] ?? null) !== $expected || ! in_array($expected['mode'], ['owner', 'central', 'unresolved'], true)) {
                throw new OwnershipViolation('Owner batches must be captured and dispatched in their producer scope.');
            }
            foreach ($batch->jobs->flatten() as $job) {
                if (! is_object($job) || $app->make(OwnershipQueueCarrier::class)->envelope($job)->toArray() !== $expected) {
                    throw new OwnershipViolation('A batch cannot mix captured owner contexts.');
                }
            }
        }
        $this->validateSerializedOptions($this->serialize($batch->options));

        return parent::store($batch);
    }
}
