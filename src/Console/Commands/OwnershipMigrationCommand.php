<?php

declare(strict_types=1);

namespace Nvl\Ownership\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Nvl\Ownership\ValueObjects\OwnershipOperation;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/** Shared input validation for migration phases; CLI facts never authorize a cutover. @internal */
abstract class OwnershipMigrationCommand extends Command
{
    /** Require a deliberate immutable plan identifier. */
    protected function planId(mixed $id): string
    {
        if (! is_string($id) || ! Str::isUuid($id)) {
            throw new OwnershipConfigurationInvalid('Supply the immutable --plan UUID from the reviewed plan.');
        }

        return $id;
    }

    /** Require an explicit local JSON input. */
    protected function inputPath(mixed $path): string
    {
        if (! is_string($path) || $path === '') {
            throw new OwnershipConfigurationInvalid('Supply --input with the reviewed JSON file.');
        }

        return $path;
    }

    /** Supply bounded audit facts to the existing fresh, default-deny host authorization boundary. */
    protected function operation(string $phase, mixed $type, mixed $id): OwnershipOperation
    {
        if (! is_string($type) || ! is_string($id)) {
            throw new OwnershipConfigurationInvalid('Supply --actor-type and --actor-id for auditing. These values do not grant authorization.');
        }

        return new OwnershipOperation('ownership.'.$phase, $type, $id);
    }
}
