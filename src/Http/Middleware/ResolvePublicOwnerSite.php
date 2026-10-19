<?php

declare(strict_types=1);

namespace Nvl\Ownership\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Nvl\Ownership\Services\PublicOwnerSiteRunner;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Admits the verified public graph before bindings, cache reads or package errors.
 *
 * @api
 */
final readonly class ResolvePublicOwnerSite
{
    public function __construct(private PublicOwnerSiteRunner $runner) {}

    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        try {
            return $this->runner->run($request, fn (): Response => $next($request));
        } catch (OwnershipViolation $exception) {
            throw new NotFoundHttpException('Site was not found.', $exception);
        }
    }
}
