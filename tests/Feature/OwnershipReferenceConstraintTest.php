<?php

declare(strict_types=1);
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipDatabaseConstraints;

it('enforces secondary references on raw inserts and updates with native owner partitions', function (): void {
    $connection = DB::connection();
    $connection->getSchemaBuilder()->create('ownership_reference_parents', static function (Blueprint $table): void {
        $table->id();
        $table->string('owner_scope_key');
    });
    $connection->getSchemaBuilder()->create('ownership_reference_children', static function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('definition_id')->nullable();
        $table->string('owner_scope_key');
    });
    $scope = 'owner:'.str_repeat('a', 64);
    $foreign = 'owner:'.str_repeat('b', 64);
    $connection->table('ownership_reference_parents')->insert([['id' => 1, 'owner_scope_key' => $scope], ['id' => 2, 'owner_scope_key' => $foreign]]);
    $constraints = app(OwnershipDatabaseConstraints::class);
    $constraints->reference($connection, 'ownership_reference_children', 'definition_id', 'ownership_reference_parents', 'id', true);
    $connection->table('ownership_reference_children')->insert(['id' => 1, 'definition_id' => 1, 'owner_scope_key' => $scope]);
    expect(fn () => $connection->transaction(fn () => $connection->table('ownership_reference_children')->insert(['id' => 2, 'definition_id' => 2, 'owner_scope_key' => $scope])))->toThrow(QueryException::class);
    expect(fn () => $connection->transaction(fn () => $connection->table('ownership_reference_children')->where('id', 1)->update(['definition_id' => 2])))->toThrow(QueryException::class);
    $connection->table('ownership_reference_children')->insert(['id' => 3, 'definition_id' => null, 'owner_scope_key' => $scope]);
    $constraints->assertReference($connection, 'ownership_reference_children', 'definition_id', 'ownership_reference_parents', 'id');
    expect($connection->table('ownership_reference_children')->count())->toBe(2);
});

it('keeps native reference triggers independent for two table prefixes on one source', function (): void {
    $connection = DB::connection();
    $original = $connection->getTablePrefix();
    $constraints = app(OwnershipDatabaseConstraints::class);
    $scope = 'owner:'.str_repeat('a', 64);
    try {
        foreach (['first_', 'second_'] as $prefix) {
            $connection->setTablePrefix($prefix);
            $connection->getSchemaBuilder()->create('reference_parents', static function (Blueprint $table): void {
                $table->id();
                $table->string('owner_scope_key');
            });
            $connection->getSchemaBuilder()->create('reference_children', static function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('definition_id');
                $table->string('owner_scope_key');
            });
            $connection->table('reference_parents')->insert(['id' => 1, 'owner_scope_key' => $prefix === 'first_' ? $scope : 'owner:'.str_repeat('b', 64)]);
            $constraints->reference($connection, 'reference_children', 'definition_id', 'reference_parents', 'id');
        }
        $connection->setTablePrefix('first_');
        $connection->table('reference_children')->insert(['id' => 1, 'definition_id' => 1, 'owner_scope_key' => $scope]);
        $constraints->assertReference($connection, 'reference_children', 'definition_id', 'reference_parents', 'id');
        expect($connection->table('reference_children')->count())->toBe(1);
        $connection->setTablePrefix('second_');
        expect(fn () => $connection->transaction(fn () => $connection->table('reference_children')->insert(['id' => 1, 'definition_id' => 1, 'owner_scope_key' => $scope])))->toThrow(QueryException::class);
        $constraints->assertReference($connection, 'reference_children', 'definition_id', 'reference_parents', 'id');
    } finally {
        $connection->setTablePrefix($original);
    }
});
