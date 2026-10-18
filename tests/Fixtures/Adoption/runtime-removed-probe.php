<?php

declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Database\Capsule\Manager;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Facade;
use Nvl\Ownership\Tests\Fixtures\Adoption\Record;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;
use Nvl\Support\Ownership\Services\InactiveOwnershipMaintenance;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Tenancy\Services\PersistedTenantStorage;
use Nvl\Support\Tenancy\Services\TenantResourceRegistry;
use Nvl\Support\Tenancy\ValueObjects\TenantResourceDefinition;

require dirname(__DIR__, 6).'/vendor/autoload.php';

$configuration = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
if (! is_array($configuration) || class_exists('Nvl\Ownership\Providers\OwnershipServiceProvider', false)
    || class_exists('Nvl\Tenancy\Providers\TenancyServiceProvider', false)) {
    throw new RuntimeException('The cold probe requires neutral Core without either runtime provider.');
}
$container = new Application;
$options = new Repository(['nvl-tenancy' => ['enabled' => false]]);
$container->instance('config', $options);
Facade::setFacadeApplication($container);
$database = new Manager($container);
$database->addConnection($configuration);
$database->bootEloquent();
$resources = new TenantResourceRegistry;
$resources->register(new TenantResourceDefinition('renamed.records', 'fixture', Record::class));
$guard = new PersistedTenantStorage($options, $resources, new OwnershipResourceRegistry, new InactiveOwnershipMaintenance);
try {
    $guard->assertUsable('renamed.records');
    fwrite(STDOUT, 'escaped');
} catch (OwnershipSchemaNotReady) {
    fwrite(STDOUT, 'blocked');
}
