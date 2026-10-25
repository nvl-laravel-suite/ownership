<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Contracts\OwnerSiteResolver;
use Nvl\Ownership\Services\OwnerIdentityResolver;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\PublicOwnerSiteRunner;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Ownership\Tests\Fixtures\Resources\UnownedAsset;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Enums\OwnershipContextMode;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Ownership\ValueObjects\VerifiedOwnerSiteContext;

beforeEach(function (): void {
    $this->allowOwners();
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.unowned', 'assets', UnownedAsset::class, UnownedAsset::ownershipDefinition()));
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
    $called = false;
    $callback = function () use (&$called): void {
        $called = true;
    };
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://unknown.public.test', $callback))->toThrow(OwnershipViolation::class)
        ->and(fn () => app(PublicOwnerSiteRunner::class)->run('http://a.public.test', $callback))->toThrow(OwnershipViolation::class);
    Company::findOrFail(7)->update(['active' => false]);
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', $callback))->toThrow(OwnershipViolation::class);
    expect($called)->toBeFalse();
});

it('restores the original scope after public resolution fails', function (): void {
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function (): never {
        expect(ColumnAsset::query()->pluck('name')->all())->toBe(['private-7']);
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

/** Configure the host mapping without importing request state into native admission.
 * @param  list<string>  $resources
 * @param  list<string>  $writable
 */
function publicOwnerSiteMapping(array $resources = ['assets.column'], array $writable = [], string $origin = 'https://a.public.test'): VerifiedOwnerSiteContext
{
    $site = new VerifiedOwnerSiteContext(app(OwnerIdentityResolver::class)->reference(Company::findOrFail(7)), 'storefront', $origin, $resources, $writable);
    app()->instance(OwnerSiteResolver::class, new class($site) implements OwnerSiteResolver
    {
        public function __construct(private readonly VerifiedOwnerSiteContext $site) {}

        public function resolve(string $origin): ?VerifiedOwnerSiteContext
        {
            return $this->site;
        }
    });

    return $site;
}

it('admits public writes and restores the surrounding owner after the callback throws', function (): void {
    publicOwnerSiteMapping(writable: ['assets.column']);
    app(OwnershipRunner::class)->run(Company::findOrFail(8), Actor::firstOrFail(), function (): void {
        $previous = app(OwnershipContext::class)->snapshot();
        expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function (): never {
            $asset = ColumnAsset::firstOrFail();
            $asset->name = 'publicly updated';
            expect($asset->save())->toBeTrue();
            throw new RuntimeException('write callback failure');
        }))->toThrow(RuntimeException::class, 'write callback failure');
        expect(app(OwnershipContext::class)->snapshot())->toBe($previous)
            ->and(ColumnAsset::query()->pluck('name')->all())->toBe(['private-8']);
    });
    expect(DB::table('ownership_test_column_assets')->where('id', 1)->value('name'))->toBe('publicly updated')
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('rejects invalid public resource declarations before callback entry', function (string $kind): void {
    $called = false;
    $exception = OwnershipConfigurationInvalid::class;
    if ($kind === 'unknown') {
        publicOwnerSiteMapping(['assets.unknown']);
    } elseif ($kind === 'invalid') {
        expect(fn () => publicOwnerSiteMapping(['Assets/invalid']))->toThrow(OwnershipViolation::class);
        expect($called)->toBeFalse();

        return;
    } else {
        publicOwnerSiteMapping(['assets.unowned'], ['assets.unowned']);
        $exception = OwnershipViolation::class;
    }
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function () use (&$called): void {
        $called = true;
    }))->toThrow($exception);
    expect($called)->toBeFalse()->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
})->with(['unknown', 'invalid', 'unowned writable']);

it('rejects missing runtime readiness before entering public work', function (): void {
    DB::table('nvl_ownership_resource_markers')->where('resource', 'assets.column')->delete();
    $called = false;
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function () use (&$called): void {
        $called = true;
    }))->toThrow(OwnershipSchemaNotReady::class);
    expect($called)->toBeFalse()->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('rejects caller constructed public contexts that bypass canonical origin capabilities', function (): void {
    $forged = new VerifiedOwnerSiteContext(app(OwnerIdentityResolver::class)->reference(Company::findOrFail(7)), 'storefront', 'https://a.public.test', ['assets.column'], ['assets.column']);
    $called = false;
    expect(fn () => app(OwnershipRunner::class)->publicSite($forged, function () use (&$called): void {
        $called = true;
    }))->toThrow(OwnershipViolation::class);
    expect($called)->toBeFalse()->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
});

it('allows identical nested public admission and rejects same site capability changes', function (): void {
    app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function (): void {
        $previous = app(OwnershipContext::class)->snapshot();
        expect(app(PublicOwnerSiteRunner::class)->run('https://a.public.test', fn (): string => ColumnAsset::firstOrFail()->name))->toBe('private-7');
        publicOwnerSiteMapping(writable: ['assets.column']);
        $called = false;
        expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function () use (&$called): void {
            $called = true;
        }))->toThrow(OwnershipViolation::class);
        expect($called)->toBeFalse()->and(app(OwnershipContext::class)->snapshot())->toBe($previous);
    });
});

it('uses canonical native origins for scheduled discovery and rechecks current availability', function (): void {
    $discover = static fn (string $origin): array => app(PublicOwnerSiteRunner::class)->run($origin, static fn (): array => ColumnAsset::query()->pluck('name')->all());
    expect($discover('https://a.public.test'))->toBe(['private-7'])
        ->and(app(OwnershipContext::class)->snapshot()->mode)->toBe(OwnershipContextMode::Unresolved);
    Company::findOrFail(7)->update(['active' => false]);
    $called = false;
    expect(fn () => app(PublicOwnerSiteRunner::class)->run('https://a.public.test', function () use (&$called): void {
        $called = true;
    }))->toThrow(OwnershipViolation::class);
    expect($called)->toBeFalse();
});
