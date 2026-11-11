<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Host-style custom builder that has not adopted the owned write boundary.
 *
 * @template TModel of Model
 *
 * @extends Builder<TModel>
 */
final class UnguardedAssetBuilder extends Builder {}
