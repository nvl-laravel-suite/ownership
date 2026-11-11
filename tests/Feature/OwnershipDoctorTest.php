<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Nvl\Ownership\Services\OwnershipDoctor;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\UnguardedBuilderAsset;
use Nvl\Support\Doctor\DoctorRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;
use Nvl\Support\Providers\DoctorServiceProvider;

it('reports unowned readiness through the standard strict JSON doctor contract', function (): void {
    $this->artisan('nvl:ownership:doctor', ['--strict' => true, '--format' => 'json'])->assertSuccessful()->expectsOutputToContain('"healthy": true');
});

it('rejects an unsupported doctor format', function (): void {
    $this->artisan('nvl:ownership:doctor', ['--format' => 'xml'])->assertFailed();
});

it('warns about owned models whose custom builder leaves scope-free writes unbounded', function (): void {
    $resources = app(OwnershipResourceRegistry::class);
    $resources->register(new OwnershipResourceDefinition('assets.column', 'assets', ColumnAsset::class, ColumnAsset::ownershipDefinition()));
    $resources->register(new OwnershipResourceDefinition('assets.unguarded', 'assets', UnguardedBuilderAsset::class, UnguardedBuilderAsset::ownershipDefinition()));

    $builders = collect(app(OwnershipDoctor::class)->inspect())->firstWhere('key', 'ownership.builders');

    expect($builders)->toMatchArray(['severity' => 'warning', 'passed' => false])
        ->and($builders['message'])->toContain(UnguardedBuilderAsset::class, 'ScopesOwnedBuilderWrites')->not->toContain(ColumnAsset::class);
});

it('labels a failing warning as a warning and keeps the ordinary gate open', function (): void {
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.unguarded', 'assets', UnguardedBuilderAsset::class, UnguardedBuilderAsset::ownershipDefinition()));
    config(['nvl-ownership.enabled' => false]);

    expect(Artisan::call('nvl:ownership:doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('[PASS] ownership.readiness', '[WARNING] ownership.builders')->not->toContain('[ERROR]')
        ->and(Artisan::call('nvl:ownership:doctor', ['--strict' => true]))->toBe(1);
});

it('reports a resource whose model cannot be inspected instead of aborting the doctor', function (): void {
    $model = new class extends ColumnAsset
    {
        protected $connection = 'ownership_unconfigured';
    };
    app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.unreachable', 'assets', $model::class, $model::ownershipDefinition()));

    $checks = collect(app(OwnershipDoctor::class)->inspect())->keyBy('key');

    expect($checks['ownership.readiness'])->toMatchArray(['severity' => 'error', 'passed' => false])
        ->and($checks['ownership.builders'])->toMatchArray(['severity' => 'warning', 'passed' => false])
        ->and($checks['ownership.builders']['message'])->toContain($model::class, 'could not be inspected');
});

it('contributes its checks to the combined Core doctor', function (bool $unadopted): void {
    $this->app->register(DoctorServiceProvider::class);
    if ($unadopted) {
        app(OwnershipResourceRegistry::class)->register(new OwnershipResourceDefinition('assets.column', 'assets', ColumnAsset::class, ColumnAsset::ownershipDefinition()));
    }
    $report = app(DoctorRegistry::class)->inspect(true, ['nvl/ownership']);

    expect(array_column($report['checks'], 'result', 'key'))->toBe(['ownership.builders' => 'pass', 'ownership.readiness' => $unadopted ? 'fail' : 'pass'])
        ->and(array_unique(array_column($report['checks'], 'package')))->toBe(['nvl/ownership'])
        ->and($report['healthy'])->toBe(! $unadopted);
    if ($unadopted) {
        expect(Artisan::call('nvl:doctor', ['--format' => 'json']))->toBe(1);
    }
})->with(['no owned resources' => false, 'an owned resource without an active generation' => true]);
