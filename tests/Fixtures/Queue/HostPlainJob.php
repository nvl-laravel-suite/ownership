<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Queue;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Nvl\Ownership\Tests\Fixtures\Resources\ColumnAsset;
use Nvl\Support\Ownership\Contracts\OwnershipContext;
use Nvl\Support\Ownership\Exceptions\OwnershipViolation;

/** Stands in for third-party work that cannot implement the ownership capture contract. */
final class HostPlainJob implements ShouldQueue
{
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public string $label = 'plain', public ?ColumnAsset $record = null) {}

    /** Record the worker's authority and whether protected resources stayed closed. */
    public function handle(): void
    {
        try {
            ColumnAsset::query()->get();
            $protected = 'exposed';
        } catch (OwnershipViolation) {
            $protected = 'refused';
        }
        DB::table('ownership_queue_probes')->insert(['stage' => 'plain-'.$protected, 'owner' => app(OwnershipContext::class)->snapshot()->mode->value, 'label' => $this->label]);
    }
}
