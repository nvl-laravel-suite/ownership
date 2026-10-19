<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Illuminate\Database\Schema\Blueprint;
use Nvl\Ownership\Contracts\OwnershipAdoptionAdapter;
use Nvl\Ownership\Services\OwnershipAdoptionStore;
use Nvl\Ownership\ValueObjects\OwnershipAdoptionPlan;
use Nvl\Ownership\ValueObjects\OwnershipBackfillResult;
use Nvl\Ownership\ValueObjects\OwnershipVerification;
use RuntimeException;

/** Exercises resumable source writes rather than fabricating installation markers. */
class RecordsAdapter implements OwnershipAdoptionAdapter
{
    /** @return list<string> */
    public function resources(): array
    {
        return ['fixture.records'];
    }

    /** Validate the package schema before data work. */
    public function prepare(OwnershipAdoptionPlan $plan): void
    {
        $callback = config('ownership_test.callback');
        if ($callback instanceof \Closure) {
            $callback();
        }
        $model = $this->resources()[0] === 'fixture.records' ? new Record : new RemoteRecord;
        if (! $model->getConnection()->getSchemaBuilder()->hasColumn('ownership_test_records', 'company_id')) {
            throw new RuntimeException('Missing source schema.');
        }
        $schema = $model->getConnection()->getSchemaBuilder();
        if (! $schema->hasColumn($model->getTable(), 'owner_scope_key')) {
            $schema->table($model->getTable(), static function (Blueprint $table): void {
                $table->string('owner_scope_key', 191)->nullable();
                $table->json('owner_reference')->nullable();
            });
        }
    }

    /** Backfill one stable source batch using the frozen reviewed mapping. */
    public function backfill(OwnershipAdoptionPlan $plan, ?string $cursor, int $limit): OwnershipBackfillResult
    {
        $key = $this->resources()[0];
        $model = $key === 'fixture.records' ? new Record : new RemoteRecord;
        $rows = $model->newQuery()->when($cursor !== null, fn ($query) => $query->where('id', '>', $cursor))->orderBy('id')->limit($limit)->get();
        foreach ($rows as $row) {
            $assignment = app(OwnershipAdoptionStore::class)->forRecord($plan, $key, $row->nativeId());
            $row->forceFill(['company_id' => $assignment->requireOwner()->key, 'owner_scope_key' => $assignment->requireOwner()->scopeKey(), 'owner_reference' => json_encode($assignment->requireOwner()->toArray(), JSON_THROW_ON_ERROR)])->save();
        }

        $last = $rows->last();

        return new OwnershipBackfillResult($rows->count() === $limit && $last !== null ? $last->nativeId() : null, $rows->count());
    }

    /** Verify every physical source row has its exact reviewed owner. */
    public function verify(OwnershipAdoptionPlan $plan): OwnershipVerification
    {
        $key = $this->resources()[0];
        $model = $key === 'fixture.records' ? new Record : new RemoteRecord;
        foreach ($model->newQuery()->get() as $row) {
            $assignment = app(OwnershipAdoptionStore::class)->forRecord($plan, $key, $row->nativeId());
            $nativeOwner = $row->getAttribute('company_id');
            if ((! is_int($nativeOwner) && ! is_string($nativeOwner)) || (string) $nativeOwner !== $assignment->requireOwner()->key) {
                return new OwnershipVerification(['owner_mismatch']);
            }
        }

        return new OwnershipVerification([]);
    }

    /** Simulate a nontransactional constraint phase interrupted before publication. */
    public function activate(OwnershipAdoptionPlan $plan): void
    {
        if (config('ownership_test.interrupt') === $this->resources()[0]) {
            throw new RuntimeException('Interrupted constraint application.');
        }
    }
}
