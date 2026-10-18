<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvl\Support\Config\PackageStorage;
use Nvl\Support\Ownership\Services\OwnershipStorageTables as Tables;

/** Installs the optional protocol on each explicitly participating database. */
return new class extends Migration
{
    /** Resolve the explicitly configured protocol storage. */
    public function getConnection(): ?string
    {
        return PackageStorage::connection('ownership');
    }

    /** Create additive durable identity, history and fail-closed activation barriers. */
    public function up(): void
    {
        $schema = Schema::connection($this->getConnection());
        $schema->create(Tables::Stores, static function (Blueprint $table): void {
            $table->unsignedSmallInteger('id')->primary();
            $table->uuid('store_id')->unique();
        });
        DB::connection($this->getConnection())->table(Tables::Stores)->insert(['id' => 1, 'store_id' => (string) Str::uuid()]);
        $schema->create(Tables::Runs, static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 32);
            $table->char('mapping_hash', 64);
            $table->char('configuration_hash', 64);
            $table->json('packages');
            $table->json('checkpoints');
            $table->timestamps();
            $table->index(['status', 'updated_at'], 'nvl_own_runs_status_idx');
        });
        $schema->create(Tables::Checkpoints, static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('run_id');
            $table->string('phase', 32);
            $table->json('checkpoint');
            $table->timestamp('created_at');
            $table->foreign('run_id', 'nvl_own_checkpoints_run_fk')->references('id')->on(Tables::Runs)->restrictOnDelete();
        });
        $schema->create(Tables::Markers, static function (Blueprint $table): void {
            $table->string('resource', 191)->primary();
            $table->string('physical_table', 191);
            $table->string('state', 32);
            $table->unsignedInteger('schema_version');
            $table->char('configuration_hash', 64);
            $table->uuid('generation');
            $table->uuid('coordinator_store');
            $table->uuid('source_store');
            $table->timestamps();
            $table->unique('physical_table', 'nvl_own_markers_table_unique');
        });
        $schema->create(Tables::Mappings, static function (Blueprint $table): void {
            $table->uuid('run_id');
            $table->string('resource', 191);
            $table->string('record_id', 191);
            $table->json('owner_reference');
            $table->string('legacy_tenant_id', 191)->nullable();
            $table->json('metadata');
            $table->unique(['run_id', 'resource', 'record_id'], 'nvl_own_mappings_record_unique');
            $table->index(['legacy_tenant_id', 'run_id'], 'nvl_own_mappings_legacy_idx');
            $table->foreign('run_id', 'nvl_own_mappings_run_fk')->references('id')->on(Tables::Runs)->restrictOnDelete();
        });
        $schema->create(Tables::Operations, static function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('actor_type', 255);
            $table->string('actor_id', 255);
            $table->string('purpose', 255);
            $table->json('owner_reference')->nullable();
            $table->timestamp('created_at');
            $table->index(['actor_type', 'actor_id'], 'nvl_own_operations_actor_idx');
        });
    }

    /** Require explicit retirement; rolling back metadata would remove isolation evidence. */
    public function down(): void
    {
        throw new LogicException('Ownership protocol history requires an explicit reviewed retirement migration.');
    }
};
