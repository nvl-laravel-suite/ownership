<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Closure;
use Illuminate\Bus\Batch;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Throwable;

/**
 * Persists boundary observations without retaining a producer or worker context.
 *
 * @phpstan-type Observation array{stage: string, label: string, mode: string, owner: string|null, principal_kind: string|null, principal_type: string|null, principal_id: string|null, operation: string|null, locale: string, process_id: int}
 */
final readonly class QueueStateProbe
{
    /** Install observations separately from the existing native queue fixture schema. */
    public static function install(): void
    {
        DB::getSchemaBuilder()->create('ownership_queue_state_probes', static function (Blueprint $table): void {
            $table->id();
            $table->string('stage');
            $table->string('label');
            $table->string('mode');
            $table->string('owner')->nullable();
            $table->string('principal_kind')->nullable();
            $table->string('principal_type')->nullable();
            $table->string('principal_id')->nullable();
            $table->string('operation')->nullable();
            $table->string('locale');
            $table->unsignedBigInteger('process_id');
        });
    }

    /** Observe the currently admitted state before the worker can reset scoped services. */
    public static function record(string $stage, string $label): void
    {
        $snapshot = app(OwnershipContext::class)->snapshot();
        DB::table('ownership_queue_state_probes')->insert([
            'stage' => $stage,
            'label' => $label,
            'mode' => $snapshot->mode->value,
            'owner' => $snapshot->owner?->key,
            'principal_kind' => $snapshot->principal['kind'] ?? null,
            'principal_type' => $snapshot->principal['type'] ?? null,
            'principal_id' => $snapshot->principal['id'] ?? null,
            'operation' => $snapshot->operation,
            'locale' => app()->getLocale(),
            'process_id' => (int) getmypid(),
        ]);
    }

    /**
     * Read only public observation columns in their actual execution order.
     *
     * @return list<Observation>
     */
    public static function observations(?string $stage = null): array
    {
        $query = DB::table('ownership_queue_state_probes');
        if ($stage !== null) {
            $query->where('stage', $stage);
        }
        /** @var list<object{stage: string, label: string, mode: string, owner: string|null, principal_kind: string|null, principal_type: string|null, principal_id: string|null, operation: string|null, locale: string, process_id: int|string}> $records */
        $records = $query->orderBy('id')->get()->all();

        $observations = [];
        foreach ($records as $record) {
            $observations[] = [
                'stage' => $record->stage,
                'label' => $record->label,
                'mode' => $record->mode,
                'owner' => $record->owner,
                'principal_kind' => $record->principal_kind,
                'principal_type' => $record->principal_type,
                'principal_id' => $record->principal_id,
                'operation' => $record->operation,
                'locale' => $record->locale,
                'process_id' => (int) $record->process_id,
            ];
        }

        return $observations;
    }

    /** @return Closure(Batch): void */
    public static function batchCallback(string $stage, string $label): Closure
    {
        return static function (Batch $batch) use ($stage, $label): void {
            self::record($stage, $label);
        };
    }

    /** @return Closure(Batch, Throwable): void */
    public static function batchFailureCallback(string $label): Closure
    {
        return static function (Batch $batch, Throwable $exception) use ($label): void {
            self::record('catch', $label);
        };
    }

    private function __construct() {}
}
