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
        public function resolve(string $origin): ?VerifiedOwnerSiteContext
        {
            $host = parse_url($origin, PHP_URL_HOST);
            if (! in_array($host, ['a.public.test', 'b.public.test'], true)) {
                return null;
            }
            $id = $host === 'a.public.test' ? 7 : 8;

            return new VerifiedOwnerSiteContext(app(OwnerIdentityResolver::class)->reference(Company::findOrFail($id)), 'storefront', 'https://'.$host, ['assets.column']);
        }
    });
});

it('admits only the canonical host owner and restores public state without actor rights', function (): void {
    foreach (['a' => 'private-7', 'b' => 'private-8'] as $host => $name) {
        $request = Request::create('https://'.$host.'.public.test/?owner=8&site=forged');
        $request->headers->set('X-Owner-Id', '8');
        app(PublicOwnerSiteRunner::class)->run($request->getSchemeAndHttpHost(), function () use ($name): void {
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
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://unknown.public.test', fn () => 'leak'))->toThrow(OwnershipViolation::class)
        ->and(fn () => app(PublicOwnerSiteRunner::class)->run('http://a.public.test', fn () => 'leak'))->toThrow(OwnershipViolation::class);
    Company::findOrFail(7)->update(['active' => false]);
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', fn () => 'leak'))->toThrow(OwnershipViolation::class);
});

it('restores the original scope after public resolution fails', function (): void {
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function (): never {
        throw new RuntimeException('public failure');
    }))->toThrow(RuntimeException::class, 'public failure');
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('normalizes native origin casing before resolving the canonical public site', function (): void {
    $result = app(PublicOwnerSiteRunner::class)->run('HTTPS://A.PUBLIC.TEST', fn (): string => ColumnAsset::firstOrFail()->name);

    expect($result)->toBe('private-7');
});

it('rejects malformed native origins before entering the public callback', function (string $origin): void {
    $called = false;

    expect(fn () => app(PublicOwnerSiteRunner::class)->run($origin, function () use (&$called): void {
        $called = true;
    }))->toThrow(OwnershipViolation::class);
    expect($called)->toBeFalse();
})->with([
    'path' => 'https://a.public.test/private',
    'query' => 'https://a.public.test?owner=8',
    'credentials' => 'https://attacker@a.public.test',
    'fragment' => 'https://a.public.test#other',
    'whitespace' => ' https://a.public.test',
    'control characters' => "https://a.public.test\r\nHost: b.public.test",
    'unsupported protocol' => 'file://a.public.test',
]);

it('rejects switching public sites within an admitted callback', function (): void {
    app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function (): void {
        expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://b.public.test', fn (): string => 'leak'))
            ->toThrow(OwnershipViolation::class);
        expect(ColumnAsset::query()->pluck('name')->all())->toBe(['private-7']);
    });
    expect(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('rejects malformed public capability lists through the ownership failure contract', function (): void {
    $owner = app(OwnerIdentityResolver::class)->reference(Company::findOrFail(7));
    expect(fn () => new VerifiedOwnerSiteContext($owner, 'default', 'https://a.public.test', ['assets.column'], [new stdClass]))
        ->toThrow(OwnershipViolation::class);
});
