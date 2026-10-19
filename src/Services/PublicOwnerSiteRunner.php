<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Contracts\OwnerSiteResolver;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;

/**
 * Revalidates trusted site mappings and native owner availability at every public boundary.
 * Also supports synchronous scheduled discovery by resolving the same canonical-origin request.
 *
 * @api
 */
final readonly class PublicOwnerSiteRunner
{
    public function __construct(private Container $container) {}

    /**
     * Enter only the resolved owner/site resource graph, then restore all scoped state.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(Request $request, Closure $callback): mixed
    {
        if (! $this->container->bound(OwnerSiteResolver::class)) {
            throw new OwnershipViolation('No public owner site resolver is configured.');
        }
        $site = $this->container->make(OwnerSiteResolver::class)->resolve($request);
        if ($site === null || $site->canonicalOrigin !== $request->getSchemeAndHttpHost()) {
            throw new OwnershipViolation('The request does not match an admitted canonical site origin.');
        }
        $this->container->make(OwnershipConfiguration::class)->assertReady();
        $canonical = $this->container->make(OwnerIdentityResolver::class)->resolve($site->owner);
        $this->container->make(OwnerAvailability::class)->assertAvailable($canonical);
        $resources = $this->container->make(OwnershipResourceRegistry::class);
        foreach ($site->resources as $resource) {
            $resources->get($resource);
        }
        foreach ($site->writableResources as $resource) {
            if ($resources->get($resource)->ownership->strategy === 'unscoped') {
                throw new OwnershipViolation('Public write capabilities require an owned resource.');
            }
        }

        $snapshot = $this->container->make(OwnershipContext::class)->snapshot();
        if ($snapshot->publicSite !== null) {
            $this->container->make(OwnershipInstallationState::class)->assertSnapshot($snapshot);
            if ($snapshot->publicSite != $site) {
                throw new OwnershipViolation('Public admission cannot switch sites or capabilities.');
            }

            return $callback();
        }

        return $this->container->make(OwnershipRunner::class)->publicSite($site, $callback);
    }
}
