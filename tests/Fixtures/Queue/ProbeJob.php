<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Contracts\OwnershipQueuedJob;
use Nvl\Support\Ownership\ValueObjects\OwnershipJobEnvelope;
use RuntimeException;
use Throwable;

/** Observes the actual native restoration boundary, model serialization and execution. */
class ProbeJob implements OwnershipQueuedJob, ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use SerializesModels {
        __unserialize as private restoreSerializedModels;
    }

    /** @var list<array{stage: string, owner: string|null, label: string}> */
    public static array $observations = [];

    public OwnershipJobEnvelope $ownership;

    public int $tries = 1;

    /** Capture once at construction, before any native scheduling method. */
    public function __construct(public string $label = 'probe', public ?ColumnAsset $record = null, public bool $throws = false)
    {
        $this->ownership = OwnershipJobEnvelope::capture(app(OwnershipContext::class));
    }

    /** Return explicit producer capture. */
    public function ownershipJobEnvelope(): OwnershipJobEnvelope
    {
        return $this->ownership;
    }

    /** @param array<string, mixed> $values */
    public function __unserialize(array $values): void
    {
        self::observe('restore', 'restoring');
        $this->restoreSerializedModels($values);
    }

    /** Record the active owner before optional native retry behavior. */
    public function handle(): void
    {
        self::observe('handle', $this->label);
        if ($this->throws && ($this->label !== 'retry-A' || $this->attempts() === 1)) {
            throw new RuntimeException('Fixture queued failure.');
        }
    }

    /** Record terminal native failure scope. */
    public function failed(?Throwable $exception): void
    {
        self::observe('failed', $this->label);
    }

    /** Persist only in actual worker scenarios; focused tests keep their native static observations. */
    private static function observe(string $stage, string $label): void
    {
        $observation = ['stage' => $stage, 'owner' => app(OwnershipContext::class)->snapshot()->owner?->key, 'label' => $label];
        self::$observations[] = $observation;
        if (DB::getSchemaBuilder()->hasTable('ownership_queue_probes')) {
            DB::table('ownership_queue_probes')->insert($observation);
        }
    }
}
