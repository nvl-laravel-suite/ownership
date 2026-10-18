<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Illuminate\Database\QueryException;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$configuration = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($configuration)) {
    throw new RuntimeException('Invalid dedicated assignment test source.');
}
$database = new Manager;
$database->addConnection($configuration);
$connection = $database->getConnection();
$connection->getPdo();
fwrite(STDOUT, "ready\n");
fflush(STDOUT);
$deadline = microtime(true) + 10;
while (! is_file($argv[4])) {
    if (microtime(true) > $deadline) {
        throw new RuntimeException('Assignment race barrier timed out.');
    }
    usleep(1000);
}
try {
    $connection->transaction(function () use ($connection, $argv): void {
        $connection->table('ownership_test_pivot_assets')->insert(['id' => 99, 'name' => 'contended', 'owner_scope_key' => $argv[3]]);
        $connection->table('ownership_test_asset_owners')->insert(['asset_id' => 99, 'company_id' => $argv[2], 'owner_scope_key' => $argv[3]]);
    });
    fwrite(STDOUT, 'assigned');
} catch (QueryException) {
    fwrite(STDOUT, 'rejected');
}
