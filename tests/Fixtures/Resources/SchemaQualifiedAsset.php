<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

/** Column-owned resource whose model names its PostgreSQL schema explicitly. */
class SchemaQualifiedAsset extends ColumnAsset
{
    protected $table = 'ownership_tenant.documents';
}
