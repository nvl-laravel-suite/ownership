<?php

declare(strict_types=1);

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipAdoptionLock;
use Symfony\Component\Process\Process;

it('serializes real competing processes across source DDL and releases the native lock', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'nvl-ownership-lock-');
    $configuration = getenv('NVL_OWNERSHIP_TEST_DRIVER') === 'pgsql'
        ? config('database.connections.sqlite')
        : ['driver' => 'sqlite', 'database' => $path, 'prefix' => '', 'foreign_key_constraints' => true];
    config(['database.connections.ownership_lock' => $configuration]);
    $connection = DB::connection('ownership_lock');
    $table = 'nvl_ownership_lock_'.bin2hex(random_bytes(6));
    $worker = static fn (): Process => new Process([PHP_BINARY, __DIR__.'/../Fixtures/Adoption/lock-worker.php', json_encode($configuration, JSON_THROW_ON_ERROR), $table], timeout: 10);
    try {
        (new OwnershipAdoptionLock)->during($connection, function () use ($connection, $table, $worker): void {
            $connection->getSchemaBuilder()->create($table, static function (Blueprint $blueprint): void {
                $blueprint->string('value');
            });
            $contender = $worker();
            $contender->mustRun();
            expect($contender->getOutput())->toBe('blocked')
                ->and($connection->table($table)->count())->toBe(0);
        });
        $successor = $worker();
        $successor->mustRun();
        expect($successor->getOutput())->toBe('acquired')
            ->and($connection->table($table)->count())->toBe(1);
    } finally {
        $connection->getSchemaBuilder()->dropIfExists($table);
        DB::purge('ownership_lock');
        if (is_string($path)) {
            @unlink($path);
            @unlink($path.'.nvl-adoption.lock');
        }
    }
});
