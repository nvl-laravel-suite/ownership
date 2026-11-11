<?php

declare(strict_types=1);

namespace Nvl\Ownership\Tests\Fixtures\Resources;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Nvl\Support\Ownership\ValueObjects\OwnershipDefinition;

/** Inherited child whose own table and canonical parent are both schema-qualified. */
final class SchemaQualifiedChildAsset extends ColumnAsset
{
    protected $table = 'ownership_tenant.document_lines';

    /** Retain the package-owned parent relationship. */
    public static function ownershipDefinition(): OwnershipDefinition
    {
        return OwnershipDefinition::inherited('document');
    }

    /** @return BelongsTo<SchemaQualifiedAsset, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SchemaQualifiedAsset::class, 'document_id');
    }
}
