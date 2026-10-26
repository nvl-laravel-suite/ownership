<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use __PHP_Incomplete_Class;
use Illuminate\Bus\BatchRepository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\ModelIdentifier;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Nvl\Ownership\Queue\OwnershipCapturedModels;
use Nvl\Ownership\Queue\OwnershipDatabaseBatchRepository;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipQueueCarrier;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipSnapshot;
use Throwable;

/**
 * Inspects native PHP payload data without instantiating command or model classes.
 *
 * @internal
 */
final readonly class OwnershipQueueCommand
{
    /** Resolve ownership services from the current worker scope. */
    public function __construct(private Container $container) {}

    /**
     * Validate the native root class and all supported model identifiers before user deserialization.
     *
     * @param  array<string, mixed>  $data
     */
    public function validate(array $data, OwnershipJobEnvelope $envelope): void
    {
        if ($envelope->context()->mode !== OwnershipContextMode::Disabled
            && (! is_string($data['commandName']) || (! is_subclass_of($data['commandName'], OwnershipQueuedJob::class) && ! $this->container->make(OwnershipQueueCarrier::class)->nativeWrapper($data['commandName'])))) {
            throw new OwnershipViolation('Enabled owner commands require the captured envelope contract.');
        }
        $serialized = $data['command'];
        if (! is_string($serialized) || ! is_string($data['commandName'])) {
            throw new OwnershipViolation('The queued command representation is invalid.');
        }
        if (! str_starts_with($serialized, 'O:')) {
            if (! $this->container->bound(Encrypter::class)) {
                throw new OwnershipViolation('Encrypted queue jobs require the native encrypter.');
            }
            $plaintext = $this->container->make(Encrypter::class)->decrypt($serialized, false);
            if (! is_string($plaintext)) {
                throw new OwnershipViolation('The native queue encrypter must return a serialized string.');
            }
            $serialized = $this->decode($plaintext);
            if (! is_string($serialized)) {
                throw new OwnershipViolation('The encrypted queue command must contain a serialized string.');
            }
        }
        $header = 'O:'.strlen($data['commandName']).':"'.$data['commandName'].'":';
        if (! str_starts_with($serialized, $header)) {
            throw new OwnershipViolation('The serialized root class differs from its payload declaration.');
        }
        $value = $this->decode($serialized);
        if (! $value instanceof __PHP_Incomplete_Class || ((array) $value)['__PHP_Incomplete_Class_Name'] !== $data['commandName']) {
            throw new OwnershipViolation('The queued command class differs from its payload declaration.');
        }
        $properties = (array) $value;
        unset($properties['__PHP_Incomplete_Class_Name']);
        if ($envelope->context()->mode !== OwnershipContextMode::Disabled) {
            $carriers = match ($data['commandName']) {
                SendQueuedMailable::class => [$properties['mailable'] ?? null],
                SendQueuedNotifications::class => [$properties['notification'] ?? null],
                CallQueuedListener::class => is_array($properties['data'] ?? null) ? $properties['data'] : [],
                default => [$value],
            };
            $found = false;
            foreach ($carriers as $carrier) {
                if ($carrier instanceof __PHP_Incomplete_Class && is_string(((array) $carrier)['__PHP_Incomplete_Class_Name'] ?? null)
                    && is_subclass_of(((array) $carrier)['__PHP_Incomplete_Class_Name'], OwnershipQueuedJob::class)
                    && $this->containsEnvelope($carrier, 0)) {
                    $found = true;
                } elseif (is_object($carrier)) {
                    throw new OwnershipViolation('The native wrapper carrier lacks explicit owner capture.');
                }
            }
            if (! $found) {
                throw new OwnershipViolation('Owner commands and native wrappers must carry a serialized captured envelope.');
            }
            $batchId = $properties['batchId'] ?? null;
            if ($batchId !== ($data['batchId'] ?? null)) {
                throw new OwnershipViolation('The serialized command differs from its declared batch.');
            }
            if (is_string($batchId)) {
                $repository = $this->container->make(BatchRepository::class);
                if (! OwnershipDatabaseBatchRepository::compatible($repository)) {
                    throw new OwnershipConfigurationInvalid('Owner batches require a compatible native database batch repository.');
                }
                $batch = $repository->find($batchId);
                if ($batch === null || ($batch->options['nvl_ownership'] ?? null) !== $envelope->toArray()) {
                    throw new OwnershipViolation('The persisted batch differs from the queued command context.');
                }
            }
        }
        $this->inspect($properties, $envelope, 0);
    }

    /** Parse with all user classes disabled; malformed bytes never reach native deserialization. */
    private function decode(string $serialized): mixed
    {
        if (str_contains($serialized, '__PHP_Incomplete_Class_Name')) {
            throw new OwnershipViolation('Reserved serialization inspection metadata is not supported in queued commands.');
        }
        set_error_handler(static function (): never {
            throw new OwnershipViolation('The serialized queued command is malformed.');
        });
        try {
            return unserialize($serialized, ['allowed_classes' => false, 'max_depth' => 64]);
        } catch (Throwable) {
            throw new OwnershipViolation('The serialized queued command is malformed.');
        } finally {
            restore_error_handler();
        }
    }

    /** Inspect inert values with bounded depth, including collections and private job properties. */
    private function inspect(mixed $value, OwnershipJobEnvelope $envelope, int $depth): void
    {
        if ($depth > 64) {
            throw new OwnershipViolation('The queued command graph exceeds the supported depth.');
        }
        if (is_object($value)) {
            if ($value instanceof __PHP_Incomplete_Class) {
                $properties = (array) $value;
                $class = $properties['__PHP_Incomplete_Class_Name'];
                if ($class === 'Laravel\\SerializableClosure\\Serializers\\Signed') {
                    if (! is_string($properties['serializable'] ?? null)) {
                        throw new OwnershipViolation('Signed callbacks require their native serialized closure representation.');
                    }
                    $this->inspect($this->decode($properties['serializable']), $envelope, $depth + 1);
                }
                if (is_string($class) && is_a($class, Model::class, true) && $envelope->context()->mode !== OwnershipContextMode::Disabled) {
                    throw new OwnershipViolation('Hydrated model objects cannot replace canonical queued model identifiers.');
                }
                if ($class === OwnershipJobEnvelope::class && $envelope->context()->mode !== OwnershipContextMode::Disabled) {
                    if (! is_array($properties['data'] ?? null) || OwnershipJobEnvelope::fromArray($properties['data'])->toArray() !== $envelope->toArray()) {
                        throw new OwnershipViolation('A carried envelope differs from the queued ownership boundary.');
                    }
                }
                if (is_string($class) && is_a($class, ModelIdentifier::class, true) && $envelope->context()->mode !== OwnershipContextMode::Disabled) {
                    if (is_subclass_of($class, ModelIdentifier::class)) {
                        throw new OwnershipViolation('Queued model identifier subtypes are not supported.');
                    }
                    $this->validateModel($properties);
                }
                unset($properties['__PHP_Incomplete_Class_Name']);
                $this->inspect($properties, $envelope, $depth + 1);
            }
        } elseif (is_array($value)) {
            foreach ($value as $key => $item) {
                if ($key === 'chained' && is_array($item)) {
                    foreach ($item as $serialized) {
                        if (! is_string($serialized) || preg_match('/\AO:([0-9]+):"([^"]+)":/', $serialized, $matches) !== 1
                            || (int) $matches[1] !== strlen($matches[2])) {
                            throw new OwnershipViolation('Chains require native serialized object commands.');
                        }
                        $chain = $this->decode($serialized);
                        if (! $chain instanceof __PHP_Incomplete_Class || ((array) $chain)['__PHP_Incomplete_Class_Name'] !== $matches[2]) {
                            throw new OwnershipViolation('The chained command representation is invalid.');
                        }
                        if ($envelope->context()->mode !== OwnershipContextMode::Disabled
                            && (! is_subclass_of($matches[2], OwnershipQueuedJob::class) || ! $this->containsEnvelope($chain, $depth + 1))) {
                            throw new OwnershipViolation('Chained owner jobs require the captured envelope contract.');
                        }
                        $this->inspect($chain, $envelope, $depth + 1);
                    }
                }
                $this->inspect($item, $envelope, $depth + 1);
            }
        }
    }

    /** Recheck canonical ownership of restored models before handle or failed callbacks. */
    public function validateRestored(mixed $command): void
    {
        $this->container->make(OwnershipCapturedModels::class)->validate($command);
    }

    /** Locate immutable capture in inert objects without invoking carrier code. */
    private function containsEnvelope(mixed $value, int $depth): bool
    {
        if ($depth > 64) {
            return false;
        }
        if ($value instanceof __PHP_Incomplete_Class) {
            $value = (array) $value;
            if (($value['__PHP_Incomplete_Class_Name'] ?? null) === OwnershipJobEnvelope::class) {
                return true;
            }
        }
        if (is_array($value)) {
            foreach ($value as $item) {
                if ($this->containsEnvelope($item, $depth + 1)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Decode and inspect native batch options without executing callbacks.
     *
     * @internal
     */
    public function batchEnvelope(string $serialized): OwnershipJobEnvelope
    {
        $options = $this->decode($serialized);
        if (! is_array($options)) {
            throw new OwnershipViolation('Native batch options must be an array.');
        }
        if ($this->container->make('config')->get('nvl-ownership.enabled') !== true) {
            if (array_key_exists('nvl_ownership', $options) && ! is_array($options['nvl_ownership'])) {
                throw new OwnershipViolation('Persisted batch context is malformed.');
            }
            $envelope = array_key_exists('nvl_ownership', $options)
                ? OwnershipJobEnvelope::fromArray($options['nvl_ownership'])
                : OwnershipJobEnvelope::fromSnapshot(new OwnershipSnapshot(OwnershipContextMode::Disabled));
            $this->container->make(OwnershipQueueContext::class)->run($envelope, fn () => $this->inspect($options, $envelope, 0));

            return $envelope;
        }
        if (! is_array($options['nvl_ownership'] ?? null)) {
            throw new OwnershipViolation('Owner batches require explicitly captured options.');
        }
        $envelope = OwnershipJobEnvelope::fromArray($options['nvl_ownership']);
        $context = $this->container->make(OwnershipContext::class);
        $unowned = $envelope->context()->mode === OwnershipContextMode::Unresolved;
        if ($unowned && $context->snapshot()->mode !== OwnershipContextMode::Unresolved) {
            throw new OwnershipViolation('Global batch callbacks cannot borrow owner or central authority.');
        }
        $current = $unowned ? $this->container->make(OwnershipQueueContext::class)->captureUnowned() : OwnershipJobEnvelope::capture($context);
        if ($envelope->context()->mode === OwnershipContextMode::Disabled
            || $current->toArray() !== $options['nvl_ownership']) {
            throw new OwnershipViolation('Persisted batch callbacks belong to a different owner scope.');
        }
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        $this->inspect($options, $envelope, 0);

        return $envelope;
    }

    /** @param array<array-key, mixed> $properties */
    private function validateModel(array $properties): void
    {
        $definition = null;
        foreach ($this->container->make(OwnershipResourceRegistry::class)->all() as $resource) {
            if ($resource->model === ($properties['class'] ?? null)) {
                $definition = $resource;
                break;
            }
        }
        if ($definition === null || ($properties['relations'] ?? null) !== [] || ($properties['collectionClass'] ?? null) !== null) {
            throw new OwnershipViolation('Queued model restoration requires a registered resource without serialized relations or custom collections.');
        }
        $model = new $definition->model;
        $connection = $properties['connection'] ?? null;
        if ($connection !== null && ! is_string($connection)) {
            throw new OwnershipViolation('Queued model connections must be null or a connection name.');
        }
        $canonicalConnection = $model->getConnection();
        if ($model->setConnection($connection)->getConnection() !== $canonicalConnection) {
            throw new OwnershipViolation('Queued models must use their canonical connection.');
        }
        $ids = $properties['id'] ?? null;
        foreach (is_array($ids) ? $ids : [$ids] as $id) {
            if (! is_string($id) && ! is_int($id)) {
                throw new OwnershipViolation('Queued model identifiers must be scalar.');
            }
            $record = new $definition->model;
            $record->setRawAttributes([$record->getKeyName() => $id], true);
            $record->exists = true;
            $fresh = $this->container->make(OwnershipRecordReader::class)->persisted($record, $definition);
            $this->container->make(OwnershipBoundary::class)->assertRecord($fresh, $definition->key);
        }
    }
}
