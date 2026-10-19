<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Nvl\Ownership\Contracts\OwnerSiteResolver;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\PublicOwnerSiteRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
    $this->app->bind(OwnerSiteResolver::class, static fn () => new class implements OwnerSiteResolver
    {
        public function resolve(Request $request): ?VerifiedOwnerSiteContext
        {
            if (! in_array($request->getHost(), ['a.public.test', 'b.public.test'], true)) {
                return null;
            }
            $id = $request->getHost() === 'a.public.test' ? 7 : 8;

            return new VerifiedOwnerSiteContext(app(OwnerIdentityResolver::class)->reference(Company::findOrFail($id)), 'storefront', 'https://'.$request->getHost(), ['assets.column']);
        }
    });
});

it('admits only the canonical host owner and restores public state without actor rights', function (): void {
    foreach (['a' => 'private-7', 'b' => 'private-8'] as $host => $name) {
        $request = Request::create('https://'.$host.'.public.test/?owner=8&site=forged');
        $request->headers->set('X-Owner-Id', '8');
        app(PublicOwnerSiteRunner::class)->run($request, function () use ($name): void {
            expect(ColumnAsset::query()->pluck('name')->all())->toBe([$name])
                ->and(app(OwnershipContext::class)->snapshot()->publicSite?->site)->toBe('storefront');
            expect(fn () => PivotAsset::query()->count())->toThrow(OwnershipViolation::class)
                ->and(fn () => ColumnAsset::firstOrFail()->forceFill(['name' => 'public mutation'])->save())->toThrow(OwnershipViolation::class)
                ->and(fn () => OwnershipJobEnvelope::capture(app(OwnershipContext::class)))->toThrow(OwnershipViolation::class)
                ->and(fn () => app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::firstOrFail(), fn () => 'escalated'))->toThrow(OwnershipViolation::class);
        });
        expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
    }
});

it('rejects unknown hosts mismatched origins and newly unavailable canonical owners', function (): void {
    expect(fn () => app(PublicOwnerSiteRunner::class)->run(Request::create('https://unknown.public.test'), fn () => 'leak'))->toThrow(OwnershipViolation::class)
        ->and(fn () => app(PublicOwnerSiteRunner::class)->run(Request::create('http://a.public.test'), fn () => 'leak'))->toThrow(OwnershipViolation::class);
    Company::findOrFail(7)->update(['active' => false]);
    expect(fn () => app(PublicOwnerSiteRunner::class)->run(Request::create('https://a.public.test'), fn () => 'leak'))->toThrow(OwnershipViolation::class);
});

it('restores the original scope after public resolution fails', function (): void {
    expect(fn () => app(PublicOwnerSiteRunner::class)->run(Request::create('https://a.public.test'), function (): never {
        throw new RuntimeException('public failure');
    }))->toThrow(RuntimeException::class, 'public failure');
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('rejects malformed public capability lists through the ownership failure contract', function (): void {
    $owner = app(OwnerIdentityResolver::class)->reference(Company::findOrFail(7));
    expect(fn () => new VerifiedOwnerSiteContext($owner, 'default', 'https://a.public.test', ['assets.column'], [new stdClass]))
        ->toThrow(OwnershipViolation::class);
});
