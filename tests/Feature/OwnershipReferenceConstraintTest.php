<?php

declare(strict_types=1);
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipDatabaseConstraints;

it('preserves native JSON no-ops and rejects immutable JSON value-type changes', function (string $mode): void {
    $connection = DB::connection();
    $connection->getSchemaBuilder()->create('ownership_immutable_json_guards', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('owner_scope_key');
        $table->jsonb('owner_reference')->nullable();
    });
    $scope = 'owner:'.str_repeat('a', 64);
    $constraints = app(OwnershipDatabaseConstraints::class);
    match ($mode) {
        'protect' => $constraints->protect($connection, 'ownership_immutable_json_guards', ['owner_reference'], false),
        'freeze' => $constraints->freeze($connection, 'ownership_immutable_json_guards', ['owner_reference']),
        default => $constraints->freezeAssigned($connection, 'ownership_immutable_json_guards', ['owner_reference']),
    };
    $payload = '{"label":"Case","number":7}';
    $connection->table('ownership_immutable_json_guards')->insert(['id' => 1, 'owner_scope_key' => $scope, 'owner_reference' => $payload]);
    $connection->table('ownership_immutable_json_guards')->where('id', 1)->update([
        'owner_reference' => $connection->getDriverName() === 'sqlite' ? $payload : $payload.' ',
    ]);
    foreach (['{"label":"case","number":7}', '{"label":"Case","number":"7"}', null] as $changed) {
        expect(fn () => $connection->transaction(fn () => $connection->table('ownership_immutable_json_guards')->where('id', 1)->update(['owner_reference' => $changed])))->toThrow(QueryException::class);
    }
    expect(json_decode($connection->table('ownership_immutable_json_guards')->value('owner_reference'), true, flags: JSON_THROW_ON_ERROR))
        ->toBe(['label' => 'Case', 'number' => 7]);
})->with(['protect', 'freeze', 'assigned']);

it('retains exact immutable text across case-insensitive SQL collations', function (string $mode): void {
    $connection = DB::connection();
    $connection->getSchemaBuilder()->create('ownership_immutable_text_guards', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('owner_scope_key');
        $table->string('external_key')->nullable();
    });
    $scope = 'owner:'.str_repeat('a', 64);
    $constraints = app(OwnershipDatabaseConstraints::class);
    match ($mode) {
        'protect' => $constraints->protect($connection, 'ownership_immutable_text_guards', ['external_key'], false),
        'freeze' => $constraints->freeze($connection, 'ownership_immutable_text_guards', ['external_key']),
        default => $constraints->freezeAssigned($connection, 'ownership_immutable_text_guards', ['external_key']),
    };
    $connection->table('ownership_immutable_text_guards')->insert(['id' => 1, 'owner_scope_key' => $scope, 'external_key' => 'alpha']);
    $connection->table('ownership_immutable_text_guards')->where('id', 1)->update(['external_key' => 'alpha']);
    foreach (['ALPHA', 'alpha ', null] as $key) {
        expect(fn () => $connection->transaction(fn () => $connection->table('ownership_immutable_text_guards')->where('id', 1)->update(['external_key' => $key])))->toThrow(QueryException::class);
    }
    expect($connection->table('ownership_immutable_text_guards')->value('external_key'))->toBe('alpha');
})->with(['protect', 'freeze', 'assigned']);

it('rejects noncanonical raw partition keys independently of SQL padding and regex anchors', function (): void {
    $connection = DB::connection();
    $owner = 'owner:'.str_repeat('a', 64);
    foreach ([false, true] as $central) {
        $table = $central ? 'ownership_central_scope_guards' : 'ownership_owner_scope_guards';
        $connection->getSchemaBuilder()->create($table, static function (Blueprint $blueprint): void {
            $blueprint->unsignedBigInteger('id')->primary();
            $blueprint->string('owner_scope_key')->nullable();
        });
        app(OwnershipDatabaseConstraints::class)->protect($connection, $table, ['owner_scope_key'], $central);
        $connection->table($table)->insert(['id' => 1, 'owner_scope_key' => $owner]);
        if ($central) {
            $connection->table($table)->insert(['id' => 2, 'owner_scope_key' => 'central']);
        } else {
            expect(fn () => $connection->transaction(fn () => $connection->table($table)->insert(['id' => 2, 'owner_scope_key' => 'central'])))->toThrow(QueryException::class);
        }
        foreach ([$owner."\n", $owner."\r\n", $owner.' ', strtoupper($owner), 'central ', "central\n", 'CENTRAL', null, ''] as $scope) {
            expect(fn () => $connection->transaction(fn () => $connection->table($table)->insert(['id' => 3, 'owner_scope_key' => $scope])))->toThrow(QueryException::class);
        }
        expect($connection->table($table)->count())->toBe($central ? 2 : 1);
    }
});

it('compares polymorphic references to exact textual native keys', function (string $kind, string $identity, array $forged): void {
    $connection = DB::connection();
    $scope = 'owner:'.str_repeat('a', 64);
    $connection->getSchemaBuilder()->create('ownership_native_key_parents', static function (Blueprint $table) use ($kind): void {
        match ($kind) {
            'uuid' => $table->uuid('id')->primary(),
            'integer' => $table->unsignedBigInteger('id')->primary(),
            default => $table->string('id')->primary(),
        };
        $table->string('owner_scope_key');
    });
    $connection->getSchemaBuilder()->create('ownership_native_key_children', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->string('subject_type');
        $table->string('subject_id');
        $table->string('owner_scope_key');
    });
    $connection->table('ownership_native_key_parents')->insert(['id' => $identity, 'owner_scope_key' => $scope]);
    $valid = ['subject_type' => 'native-parent', 'subject_id' => $identity, 'owner_scope_key' => $scope];
    $connection->table('ownership_native_key_children')->insert(['id' => 1, ...$valid]);
    $constraints = app(OwnershipDatabaseConstraints::class);
    $constraints->protect($connection, 'ownership_native_key_children', ['owner_scope_key'], false);
    $constraints->polymorphicReference($connection, 'ownership_native_key_children', 'subject_type', 'subject_id', [
        ['type' => 'native-parent', 'table' => 'ownership_native_key_parents', 'key' => 'id'],
    ]);
    $connection->table('ownership_native_key_children')->insert(['id' => 2, ...$valid]);
    foreach ($forged as $key) {
        expect(fn () => $connection->transaction(fn () => $connection->table('ownership_native_key_children')->insert(['id' => 3, ...$valid, 'subject_id' => $key])))->toThrow(QueryException::class);
        expect(fn () => $connection->transaction(fn () => $connection->table('ownership_native_key_children')->where('id', 2)->update(['subject_id' => $key])))->toThrow(QueryException::class);
    }
    expect(fn () => $connection->transaction(fn () => $connection->table('ownership_native_key_children')->insert(['id' => 3, ...$valid, 'owner_scope_key' => 'owner:'.str_repeat('b', 64)])))->toThrow(QueryException::class);
    foreach (['forged-parent', 'NATIVE-PARENT', 'native-parent '] as $type) {
        expect(fn () => $connection->transaction(fn () => $connection->table('ownership_native_key_children')->insert(['id' => 3, ...$valid, 'subject_type' => $type])))->toThrow(QueryException::class);
    }
    expect($connection->table('ownership_native_key_children')->count())->toBe(2);
})->with([
    'integer' => ['integer', '7', ['007', '7.0', '7e0', '7 ']],
    'string' => ['string', 'CaseOwner', ['caseowner', 'CaseOwner ']],
    'uuid' => ['uuid', '12345678-1234-5678-90ab-123456789abc', ['12345678-1234-5678-90AB-123456789ABC', '12345678-1234-5678-90ab-123456789abd']],
]);

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
