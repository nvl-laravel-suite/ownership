<?php

declare(strict_types=1);

namespace Nvl\Ownership\Queue;

use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Container\Container;
use Illuminate\Database\PostgresConnection;
use Nvl\Support\Ownership\Contracts\OwnershipBatchOptions;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/**
 * Retains a raw callback boundary even after the optional runtime is removed.
 *
 * @internal
 */
class OwnershipBatchRepository extends DatabaseBatchRepository
{
    protected function unserialize(mixed $serialized): mixed
    {
        $serialized = $this->nativeBytes($serialized);
        $this->validateSerializedOptions($serialized);

        return parent::unserialize($serialized);
    }

    /** Validate actual native storage values independently of inherited framework PHPDoc. */
    private function nativeBytes(mixed $serialized): string
    {
        if (! is_string($serialized)) {
            throw new OwnershipViolation('Native batch options must contain serialized bytes.');
        }

        return $serialized;
    }

    /** Share the native byte admission check with producer-side batch persistence. */
    protected function validateSerializedOptions(string $serialized): void
    {
        $raw = $serialized;
        if ($this->connection instanceof PostgresConnection && ! str_contains($raw, ':') && ! str_contains($raw, ';')) {
            $raw = base64_decode($raw, true);
            if ($raw === false) {
                throw new OwnershipViolation('Native PostgreSQL batch options are malformed.');
            }
        }
        Container::getInstance()->make(OwnershipBatchOptions::class)->validate($raw);
    }
}
