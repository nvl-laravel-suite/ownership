<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Tests\Fixtures\NativeDatabaseSource;
use Nvl\Ownership\Tests\Fixtures\Queue\SequentialWorkerProvider;
use Nvl\Ownership\Tests\Fixtures\Queue\WorkerProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$source = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($source) || ($source['driver'] ?? null) !== 'pgsql'
    || ! is_string($source['database'] ?? null) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $source['database']) !== 1) {
    throw new RuntimeException('The sequential worker requires an explicitly dedicated native source.');
}
WorkerProvider::useSource($source, ['driver' => 'database']);
$application = TestbenchApplication::create(options: ['load_environment_variables' => false, 'extra' => [
    'dont-discover' => ['*'],
    'providers' => [SupportServiceProvider::class, OwnershipServiceProvider::class, WorkerProvider::class, SequentialWorkerProvider::class],
]]);
$kernel = $application->make(Kernel::class);
$kernel->bootstrap();
NativeDatabaseSource::inspect($application->make('db')->connection());
$status = $kernel->call('queue:work', [
    'connection' => 'database', '--stop-when-empty' => true, '--sleep' => 0,
    '--tries' => 1, '--timeout' => 10, '--force' => true, '--no-interaction' => true,
]);
if ($status !== 0) {
    fwrite(STDERR, $kernel->output());
    exit($status);
}
fwrite(STDOUT, 'sequential-worker-complete');
