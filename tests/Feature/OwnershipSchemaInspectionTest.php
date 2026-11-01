<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipSchema;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Support\Ownership\Exceptions\OwnershipSchemaNotReady;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
});

it('bounds native metadata reads within each pivot schema inspection', function (): void {
    $connection = DB::connection();
    $maximumQueries = $connection->getDriverName() === 'sqlite' ? 11 : 8;
    if (in_array($connection->getDriverName(), ['mysql', 'mariadb'], true)) {
        foreach (['ownership_test_pivot_assets', 'ownership_test_asset_owners', 'ownership_test_child_assets'] as $table) {
            $column = array_find($connection->getSchemaBuilder()->getColumns($table), static fn (array $column): bool => $column['name'] === 'owner_scope_key');
            expect($column['collation'])->toBe('ascii_bin');
        }
    }
    $connection->enableQueryLog();
    try {
        foreach ([1, 2] as $inspection) {
            $connection->flushQueryLog();
            app(OwnershipSchema::class)->assertReady('assets.pivot');

            $queries = $connection->getQueryLog();
            $this->assertLessThanOrEqual($maximumQueries, count($queries), implode("\n", array_column($queries, 'query')));
        }
    } finally {
        $connection->disableQueryLog();
    }
});

it('does not retain schema readiness after a live protection is removed', function (): void {
    $schema = app(OwnershipSchema::class);
    $schema->assertReady('assets.pivot');
    $connection = DB::connection();
    $name = 'nvl_own_'.substr(hash('sha256', 'ownership_test_pivot_assets|insert|valid'), 0, 32);
    $statement = 'DROP TRIGGER '.$connection->getQueryGrammar()->wrap($name);
    if ($connection->getDriverName() === 'pgsql') {
        $statement .= ' ON ownership_test_pivot_assets';
    }
    $connection->statement($statement);

    expect(fn () => $schema->assertReady('assets.pivot'))->toThrow(OwnershipSchemaNotReady::class);
});
