<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Nvl\Support\Ownership\Traits\ScopesOwnedBuilderWrites;

/**
 * Host-style custom builder that adopts the owned write boundary.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class GuardedAssetBuilder extends Builder
{
    use ScopesOwnedBuilderWrites;
}
