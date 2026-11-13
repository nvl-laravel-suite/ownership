<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Closure;
use Illuminate\Container\Container;
use Nvl\Ownership\Contracts\OwnerAvailability;
use Nvl\Ownership\Contracts\OwnerSiteResolver;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext;

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
    public function run(string $origin, Closure $callback): mixed
    {
        $site = $this->resolve($origin);

        $snapshot = $this->container->make(OwnershipContext::class)->snapshot();
        if ($snapshot->publicSite !== null) {
            $this->container->make(OwnershipInstallationState::class)->assertSnapshot($snapshot);
            if ([$snapshot->publicSite->owner->toArray(), $snapshot->publicSite->site, $snapshot->publicSite->canonicalOrigin, $snapshot->publicSite->resources, $snapshot->publicSite->writableResources]
                !== [$site->owner->toArray(), $site->site, $site->canonicalOrigin, $site->resources, $site->writableResources]) {
                throw new OwnershipViolation('Public admission cannot switch sites or capabilities.');
            }

            return $callback();
        }

        return $this->container->make(OwnershipRunner::class)->publicSite($site, $callback);
    }

    /**
     * Resolve fresh canonical host capabilities without granting execution authority.
     *
     * @internal
     */
    public function resolve(string $origin): VerifiedOwnerSiteContext
    {
        $origin = $this->canonicalOrigin($origin);
        if (! $this->container->bound(OwnerSiteResolver::class)) {
            throw new OwnershipViolation('No public owner site resolver is configured.');
        }
        $site = $this->container->make(OwnerSiteResolver::class)->resolve($origin);
        if ($site === null || $site->canonicalOrigin !== $origin) {
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

        return $site;
    }

    /** Normalize a complete HTTP origin without accepting credentials, paths or header bytes. */
    private function canonicalOrigin(string $origin): string
    {
        $parts = parse_url($origin);
        if (preg_match('/[\x00-\x20\x7f]/', $origin) === 1
            || ! is_array($parts)
            || ! isset($parts['scheme'], $parts['host'])
            || ! in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || array_diff(array_keys($parts), ['scheme', 'host', 'port']) !== []
            || filter_var($origin, FILTER_VALIDATE_URL) === false) {
            throw new OwnershipViolation('Public admission requires a canonical HTTP origin.');
        }

        return strtolower($parts['scheme']).'://'.strtolower($parts['host'])
            .(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
