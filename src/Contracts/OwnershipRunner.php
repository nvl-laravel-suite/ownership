<?php

declare(strict_types=1);

namespace Nvl\Ownership\Contracts;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Contracts\OwnershipSystemRunner;
use Nvl\Support\Ownership\ValueObjects\OwnerReference;

/**
 * Executes host workflows inside explicitly authorized ownership boundaries.
 *
 * @api
 */
interface OwnershipRunner extends OwnershipSystemRunner
{
    /**
     * Admit an actor to a canonical owner and restore the previous context.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $operation
     * @return TResult
     */
    public function run(Model $owner, Authenticatable $actor, Closure $operation): mixed;

    /**
     * Execute one durably authorized bounded system operation.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function runSystem(OwnerReference $owner, string $operation, Closure $callback): mixed;

    /**
     * Execute one durably authorized central capability.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function central(string $operation, Authenticatable $actor, Closure $callback): mixed;
}
