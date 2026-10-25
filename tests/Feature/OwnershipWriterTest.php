<?php

declare(strict_types=1);

if (! in_array(dirname(__DIR__).'/Pest.php', get_included_files(), true)) {
    uses('Nvl\\Ownership\\Tests\\OwnershipTestCase');
}

use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Services\OwnershipRunner;
use Nvl\Ownership\Services\OwnershipWriter;
use Nvl\Ownership\Tests\Fixtures\Actor;
use Nvl\Ownership\Tests\Fixtures\Company;
use Nvl\Ownership\Tests\Fixtures\Resources\CapturedOperation;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\PivotAsset;
use Nvl\Ownership\Tests\Fixtures\Resources\ResourceScenario;
use Nvl\Ownership\ValueObjects\OwnershipAssignment;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

beforeEach(function (): void {
    $this->allowOwners();
    ResourceScenario::install($this->app);
});

it('coordinates package captured roots without accepting caller supplied ownership', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $fact = new CapturedOperation(['id' => 30, 'name' => 'package fact']);
        expect($fact->saveQuietly())->toBeTrue()
            ->and($fact->getRawOriginal('owner_scope_key'))->toStartWith('owner:');
        $forged = new CapturedOperation(['id' => 31, 'name' => 'forged', 'owner_scope_key' => 'central']);
        expect(fn () => $forged->saveQuietly())->toThrow(OwnershipViolation::class);
    });
    expect(DB::table('ownership_test_captured_operations')->where('id', 31)->exists())->toBeFalse();
});

it('atomically creates the concrete pivot owner and canonical partition on the source connection', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $asset = app(OwnershipWriter::class)->create(PivotAsset::class, fn (OwnershipAssignment $assignment): PivotAsset => PivotAsset::query()->create([...$assignment->attributes(), 'id' => 3, 'name' => 'atomic']));
        expect($asset)->toBeInstanceOf(PivotAsset::class)
            ->and(PivotAsset::query()->pluck('id')->all())->toBe([1, 3])
            ->and(DB::table('ownership_test_asset_owners')->where('asset_id', 3)->value('company_id'))->toBe(7)
            ->and(DB::transactionLevel())->toBe(0);
    });
});

it('rolls back both root and after-commit effects when its primary assignment fails', function (): void {
    $effects = [];
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function () use (&$effects): void {
        expect(fn () => app(OwnershipWriter::class)->create(PivotAsset::class, function (OwnershipAssignment $assignment) use (&$effects): PivotAsset {
            $model = PivotAsset::query()->create([...$assignment->attributes(), 'id' => 3, 'name' => 'rollback']);
            DB::afterCommit(static function () use (&$effects): void {
                $effects[] = 'committed';
            });
            DB::table('ownership_test_asset_owners')->insert(['asset_id' => 3, 'company_id' => 8, 'owner_scope_key' => $assignment->attributes()['owner_scope_key']]);

            return $model;
        }))->toThrow(QueryException::class);
    });
    expect(DB::table('ownership_test_pivot_assets')->where('id', 3)->exists())->toBeFalse()
        ->and(DB::table('ownership_test_asset_owners')->where('asset_id', 3)->exists())->toBeFalse()
        ->and($effects)->toBe([]);
});

it('rejects uncoordinated creation quiet saves and forged trusted attributes', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $model = new ColumnAsset(['id' => 3, 'name' => 'forged', 'company_id' => 7]);
        expect(fn () => $model->saveQuietly())->toThrow(OwnershipViolation::class)
            ->and(fn () => app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([...$assignment->attributes(), 'id' => 3, 'name' => 'forged', 'company_id' => 8])))->toThrow(OwnershipViolation::class);
    });
    expect(DB::table('ownership_test_column_assets')->where('id', 3)->exists())->toBeFalse();
});

it('keeps ordinary quiet updates inside a source transaction with a canonical write lock', function (): void {
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        $transactionLevel = null;
        ColumnAsset::saving(static function () use (&$transactionLevel): void {
            $transactionLevel = DB::transactionLevel();
        });
        $asset = ColumnAsset::query()->findOrFail(1);
        $asset->forceFill(['name' => 'changed'])->save();
        expect($transactionLevel)->toBeGreaterThan(0)
            ->and(DB::transactionLevel())->toBe(0);
        $asset->forceFill(['company_id' => 8]);
        expect(fn () => $asset->saveQuietly())->toThrow(OwnershipViolation::class);
    });
});

it('blocks a saving listener from redirecting SQL to another already connected database', function (): void {
    config(['database.connections.ownership_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    DB::connection('ownership_other')->getSchemaBuilder()->create('ownership_test_column_assets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id');
        $table->string('owner_scope_key', 191);
        $table->string('name');
    });
    ColumnAsset::saving(static function (ColumnAsset $model): void {
        $model->setConnection('ownership_other');
    });
    app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), function (): void {
        expect(fn () => app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([...$assignment->attributes(), 'id' => 3, 'name' => 'redirected'])))->toThrow(OwnershipViolation::class);
    });
    expect(DB::connection('ownership_other')->table('ownership_test_column_assets')->count())->toBe(0)
        ->and(DB::table('ownership_test_column_assets')->where('id', 3)->exists())->toBeFalse();
});

it('rejects a replaced source PDO before SQL and discards pending commit effects', function (): void {
    config(['database.connections.ownership_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $other = DB::connection('ownership_other');
    $other->getSchemaBuilder()->create('ownership_test_column_assets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id');
        $table->string('owner_scope_key', 191);
        $table->string('name');
    });
    $source = DB::connection();
    $original = $source->getPdo();
    $replacement = $other->getPdo();
    $effects = [];
    ColumnAsset::saving(static function () use ($source, $replacement, &$effects): void {
        DB::afterCommit(static function () use (&$effects): void {
            $effects[] = 'escaped';
        });
        $source->setPdo($replacement);
    });
    try {
        expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([...$assignment->attributes(), 'id' => 3, 'name' => 'redirected']))))->toThrow(OwnershipViolation::class);
        $source->transaction(static function (): void {});
        expect($other->table('ownership_test_column_assets')->count())->toBe(0)
            ->and($effects)->toBe([]);
    } finally {
        if ($original->inTransaction()) {
            $original->rollBack();
        }
        $source->setPdo($original);
    }
});

it('checks the source session after host SQL callbacks before executing the statement', function (): void {
    config(['database.connections.ownership_other' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
    $other = DB::connection('ownership_other');
    $other->getSchemaBuilder()->create('ownership_test_column_assets', static function (Blueprint $table): void {
        $table->unsignedBigInteger('id')->primary();
        $table->unsignedBigInteger('company_id');
        $table->string('owner_scope_key', 191);
        $table->string('name');
    });
    $source = DB::connection();
    $original = $source->getPdo();
    $replacement = $other->getPdo();
    $source->beforeExecuting(static function (string $sql) use ($source, $replacement): void {
        if (str_starts_with($sql, 'insert into "ownership_test_column_assets"')) {
            $source->setPdo($replacement);
        }
    });
    try {
        expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([...$assignment->attributes(), 'id' => 3, 'name' => 'redirected']))))->toThrow(OwnershipViolation::class);
        expect($other->table('ownership_test_column_assets')->count())->toBe(0);
    } finally {
        if ($original->inTransaction()) {
            $original->rollBack();
        }
        $source->setPdo($original);
    }
});

it('rejects an early native commit before assignment or after-commit callbacks escape', function (): void {
    $effects = [];
    ColumnAsset::saved(static function () use (&$effects): void {
        DB::afterCommit(static function () use (&$effects): void {
            $effects[] = 'escaped';
        });
        while (DB::transactionLevel() > 0) {
            DB::commit();
        }
    });
    expect(fn () => app(OwnershipRunner::class)->run(Company::query()->findOrFail(7), Actor::query()->firstOrFail(), fn () => app(OwnershipWriter::class)->create(ColumnAsset::class, fn (OwnershipAssignment $assignment): ColumnAsset => ColumnAsset::query()->create([...$assignment->attributes(), 'id' => 3, 'name' => 'early-commit']))))->toThrow(OwnershipViolation::class);
    expect(DB::table('ownership_test_column_assets')->where('id', 3)->exists())->toBeFalse()
        ->and($effects)->toBe([]);
});
