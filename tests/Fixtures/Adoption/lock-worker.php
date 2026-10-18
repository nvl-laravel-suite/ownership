<?php

declare(strict_types=1);

use Illuminate\Database\Capsule\Manager;
use Nvl\Ownership\Services\OwnershipAdoptionLock;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$configuration = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($configuration)) {
    throw new InvalidArgumentException('Invalid test connection configuration.');
}
$database = new Manager;
$database->addConnection($configuration);
$connection = $database->getConnection();
try {
    (new OwnershipAdoptionLock)->during($connection, static function () use ($connection, $argv): void {
        $connection->table($argv[2])->insert(['value' => 'worker']);
        fwrite(STDOUT, 'acquired');
    });
} catch (OwnershipViolation) {
    fwrite(STDOUT, 'blocked');
}
