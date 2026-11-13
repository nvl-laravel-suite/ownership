<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures;

/** Reset after Testbench's application callbacks, while the source is still open. */
trait ReusesAdoptedSchema
{
    private ?AdoptedSchemaFixture $adoptedSchema = null;

    protected function startAdoptedSchema(bool $enabled): void
    {
        $this->adoptedSchema = AdoptedSchemaFixture::start(static::class, $enabled);
    }

    protected function hasRetainedOwnershipSchema(): bool
    {
        return $this->adoptedSchema?->reused === true;
    }

    /** @param list<string> $seedTables Tables with fixed fixture rows, in insertion order. */
    protected function retainAdoptedOwnershipSchema(array $seedTables = []): void
    {
        $this->adoptedSchema?->observe($seedTables);
    }

    protected function callBeforeApplicationDestroyedCallbacks(): void
    {
        parent::callBeforeApplicationDestroyedCallbacks();

        if ($this->callbackException === null && $this->status()->isSuccess()) {
            $this->adoptedSchema?->reset();
        }
    }

    public static function tearDownAfterClass(): void
    {
        AdoptedSchemaFixture::forget();

        parent::tearDownAfterClass();
    }
}
