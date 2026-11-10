<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Adoption;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use LogicException;
use Nvl\Ownership\Services\OwnershipAdoptionRegistry;
use Nvl\Support\Ownership\Services\OwnershipResourceRegistry;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;
use Nvl\Support\Ownership\ValueObjects\OwnershipResourceDefinition;

/** Builds 154 distinct empty resources across nine adapters on the same SQLite store. */
final class LargeGraphFixture
{
    public const int ResourceCount = 154;

    public const int AdapterCount = 9;

    /** Register bounded fixture types without keeping hundreds of duplicate source files. */
    public static function register(): void
    {
        $owners = config('nvl-core.owners');
        if (! is_array($owners)) {
            throw new LogicException('Expected fixture owner declarations to be an array.');
        }
        config(['nvl-core.owners' => [...$owners, LargeGraphOwner::class]]);
        $resources = app(OwnershipResourceRegistry::class);
        for ($index = 1; $index <= self::ResourceCount; $index++) {
            $group = ($index - 1) % self::AdapterCount + 1;
            $table = 'ownership_scale_'.$index;
            $short = 'ScaleRecord'.$index;
            $model = __NAMESPACE__.'\\'.$short;
            if (! class_exists($model, false)) {
                eval('namespace '.__NAMESPACE__.'; class '.$short.' extends LargeGraphRecord { protected $table = '.var_export($table, true).'; }');
            }
            if (! is_a($model, LargeGraphRecord::class, true)) {
                throw new LogicException('Invalid scale fixture resource type.');
            }
            DB::getSchemaBuilder()->create($table, static function (Blueprint $schema): void {
                $schema->unsignedBigInteger('id')->primary();
                $schema->unsignedBigInteger('company_id')->nullable();
                $schema->string('owner_scope_key', 191)->nullable();
                $schema->json('owner_reference')->nullable();
            });
            $resources->register(new OwnershipResourceDefinition(self::resource($index), 'scale-'.$group, $model, OwnershipDefinition::column(LargeGraphOwner::class, 'company_id')));
        }
        for ($group = 1; $group <= self::AdapterCount; $group++) {
            app(OwnershipAdoptionRegistry::class)->register('scale-'.$group, self::adapter($group));
        }
    }

    /** @return list<string> */
    public static function resources(int $group): array
    {
        $resources = [];
        for ($index = 1; $index <= self::ResourceCount; $index++) {
            if (($index - 1) % self::AdapterCount + 1 === $group) {
                $resources[] = self::resource($index);
            }
        }

        return $resources;
    }

    /** @return list<string> */
    public static function packages(): array
    {
        return array_map(static fn (int $group): string => 'scale-'.$group, range(1, self::AdapterCount));
    }

    /** @return array{class-string<LargeGraphOwner>, class-string<LargeGraphOwner>} */
    public static function ownerReplacements(): array
    {
        $middle = __NAMESPACE__.'\\ScaleIntermediateOwner';
        $final = __NAMESPACE__.'\\ScaleFinalOwner';
        if (! class_exists($middle, false)) {
            eval('namespace '.__NAMESPACE__.'; class ScaleIntermediateOwner extends LargeGraphOwner {} class ScaleFinalOwner extends ScaleIntermediateOwner { public function getTable(): string { return (string) config("ownership_test.final_owner_table", "ownership_test_companies"); } }');
        }
        if (! is_a($middle, LargeGraphOwner::class, true) || ! is_a($final, LargeGraphOwner::class, true)) {
            throw new LogicException('Invalid scale fixture owner replacement types.');
        }

        return [$middle, $final];
    }

    /** @return class-string<LargeGraphAdapter> */
    private static function adapter(int $group): string
    {
        $short = 'ScaleAdapter'.$group;
        $adapter = __NAMESPACE__.'\\'.$short;
        if (! class_exists($adapter, false)) {
            eval('namespace '.__NAMESPACE__.'; class '.$short.' extends LargeGraphAdapter { protected int $group = '.$group.'; }');
        }
        if (! is_a($adapter, LargeGraphAdapter::class, true)) {
            throw new LogicException('Invalid scale fixture adapter type.');
        }

        return $adapter;
    }

    /** Return a stable native resource identity independent of model boot state. */
    private static function resource(int $index): string
    {
        return 'scale-'.(($index - 1) % self::AdapterCount + 1).'.record_'.$index;
    }
}
