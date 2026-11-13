<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Tests\Fixtures\NativeDatabaseSource;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Symfony\Component\Process\Process;

it('commits exactly one complete primary assignment under real competing native processes', function (): void {
    if (getenv('NVL_OWNERSHIP_TEST_DRIVER') !== 'pgsql') {
        $this->markTestSkipped('Requires the explicitly isolated native persistence database.');
    }
    $this->allowOwners();
    ResourceScenario::install($this->app);
    $barrier = tempnam(sys_get_temp_dir(), 'nvl-assignment-');
    if (! is_string($barrier)) {
        throw new RuntimeException('Cannot create the assignment race barrier.');
    }
    unlink($barrier);
    $workers = [];
    try {
        foreach ([7, 8] as $company) {
            $scope = DB::table('ownership_test_column_assets')->where('company_id', $company)->value('owner_scope_key');
            $worker = new Process([PHP_BINARY, __DIR__.'/../Fixtures/Resources/assignment-worker.php', json_encode(NativeDatabaseSource::capture(DB::connection('sqlite')), JSON_THROW_ON_ERROR), (string) $company, $scope, $barrier], timeout: 20);
            $worker->start();
            expect($worker->waitUntil(static fn (string $type, string $output): bool => str_contains($output, 'ready')))->toBeTrue();
            $workers[] = $worker;
        }
        touch($barrier);
        $outcomes = [];
        foreach ($workers as $worker) {
            $worker->wait();
            expect($worker->isSuccessful())->toBeTrue($worker->getErrorOutput());
            $outcomes[] = trim(str_replace('ready', '', $worker->getOutput()));
        }
        sort($outcomes);
        $root = DB::table('ownership_test_pivot_assets')->where('id', 99)->sole();
        $pivot = DB::table('ownership_test_asset_owners')->where('asset_id', 99)->sole();
        expect($outcomes)->toBe(['assigned', 'rejected'])
            ->and($root->owner_scope_key)->toBe($pivot->owner_scope_key)
            ->and(DB::table('ownership_test_asset_owners')->where('asset_id', 99)->count())->toBe(1);
    } finally {
        foreach ($workers as $worker) {
            $worker->stop();
        }
        @unlink($barrier);
    }
});
