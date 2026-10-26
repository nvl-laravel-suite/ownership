<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Ownership\Contracts\OwnershipWriter as WriterContract;

/**
 * Adapts package-neutral creation to the public optional runtime writer.
 *
 * @internal
 */
final readonly class PackageOwnershipWriter implements WriterContract
{
    /** Preserve the existing Runtime Writer and Assignment consumer APIs. */
    public function __construct(private OwnershipWriter $writer) {}

    /** @template T of Model
     * @param  class-string<T>  $modelClass
     * @param  Closure(array<string, int|string|null>): T  $persist
     * @return T
     */
    public function create(string $modelClass, Closure $persist): Model
    {
        return $this->writer->create($modelClass, static fn (OwnershipAssignment $assignment): Model => $persist($assignment->attributes()));
    }
}
