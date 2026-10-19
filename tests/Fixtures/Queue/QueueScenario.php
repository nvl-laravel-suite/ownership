<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;

/** Installs actual native queue storage for worker and batch integration proofs. */
final readonly class QueueScenario
{
    /** Configure native database scheduling and persistent raw quarantine. */
    public static function install(): void
    {
        config()->set([
            'queue.batching.database' => DB::getDefaultConnection(),
            'queue.connections.database.connection' => DB::getDefaultConnection(),
            'queue.failed' => ['driver' => 'database-uuids', 'database' => DB::getDefaultConnection(), 'table' => 'failed_jobs'],
        ]);
        app()->forgetInstance('queue.failer');
        $schema = DB::getSchemaBuilder();
        $schema->create('jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('queue');
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        $schema->create('failed_jobs', static function (Blueprint $table): void {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at');
        });
        $schema->create('job_batches', static function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        $schema->create('ownership_queue_probes', static function (Blueprint $table): void {
            $table->id();
            $table->string('stage');
            $table->string('owner')->nullable();
            $table->string('label');
        });
        $schema->create('cache', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
        $schema->create('cache_locks', static function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    /** @return Closure(Batch): void */
    public static function batchCallback(): Closure
    {
        return static function (Batch $batch): void {
            DB::table('ownership_queue_probes')->insert(['stage' => 'batch', 'owner' => app(OwnershipContext::class)->requireOwner()->key, 'label' => 'batch-A']);
        };
    }

    /** @return Closure(Batch): void */
    public static function unownedBatchCallback(): Closure
    {
        return static function (Batch $batch): void {
            DB::table('ownership_queue_probes')->insert(['stage' => 'unowned-batch', 'owner' => app(OwnershipContext::class)->snapshot()->mode->value, 'label' => 'global']);
        };
    }

    private function __construct() {}
}
