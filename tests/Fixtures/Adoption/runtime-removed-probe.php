<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Nvl\Ownership\Tests\Fixtures\Adoption\Record;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\Services\PersistedOwnershipStorage;
use Nvl\Support\Ownership\Services\UnownedOwnershipBoundary;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$configuration = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($configuration) || class_exists('Nvl\Ownership\Providers\OwnershipServiceProvider', false)
    || class_exists('Nvl\Tenancy\Providers\TenancyServiceProvider', false)) {
    throw new RuntimeException('The cold probe requires neutral Core without either runtime provider.');
}
$container = new Application;
$options = new Repository(['nvl-ownership' => ['enabled' => false]]);
$container->instance('config', $options);
Facade::setFacadeApplication($container);
$database = new Manager($container);
$database->addConnection($configuration);
$database->bootEloquent();
$resources = new OwnershipResourceRegistry;
$resources->register(new OwnershipResourceDefinition('renamed.records', 'fixture', Record::class, OwnershipDefinition::unscoped()));
$guard = new UnownedOwnershipBoundary($options, $resources, new PersistedOwnershipStorage);
try {
    $guard->query(Record::query(), 'renamed.records')->get();
    fwrite(STDOUT, 'escaped');
} catch (OwnershipSchemaNotReady) {
    fwrite(STDOUT, 'blocked');
}
