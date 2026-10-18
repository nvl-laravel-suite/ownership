<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Nvl\Ownership\Providers\OwnershipServiceProvider;
use Nvl\Ownership\Tests\Fixtures\Queue\WorkerProvider;
use Nvl\Support\Providers\SupportServiceProvider;
use Orchestra\Testbench\Foundation\Application as TestbenchApplication;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$connection = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($connection) || ($connection['driver'] ?? null) !== 'pgsql'
    || ! is_string($connection['database'] ?? null) || preg_match('/^nvl_own_test_[a-z0-9_]+$/D', $connection['database']) !== 1) {
    throw new RuntimeException('The worker requires an explicitly dedicated PostgreSQL persistence source.');
}
$options = isset($argv[2]) ? json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR) : [];
if (! is_array($options) || ! in_array($options['driver'] ?? 'database', ['database', 'redis'], true)) {
    throw new RuntimeException('Unsupported native persistence worker transport.');
}
WorkerProvider::useSource($connection, $options);
$application = TestbenchApplication::create(options: ['load_environment_variables' => false, 'extra' => ['dont-discover' => ['*'], 'providers' => [SupportServiceProvider::class, OwnershipServiceProvider::class, WorkerProvider::class]]]);
$kernel = $application->make(Kernel::class);
$status = $kernel->call('queue:work', ['connection' => $options['driver'] ?? 'database', '--stop-when-empty' => true, '--sleep' => 0, '--tries' => 2, '--timeout' => 10, '--force' => true, '--no-interaction' => true]);
if ($status !== 0) {
    fwrite(STDERR, $kernel->output());
    exit($status);
}
fwrite(STDOUT, 'worker-complete');
