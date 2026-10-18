<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/** A grammar-wrapped native identifier comparison without numeric coercion. @internal */
final readonly class OwnershipTextColumn implements Expression
{
    /** Accept structured canonical identifiers, never caller SQL. */
    public function __construct(private string $column, private string $driver)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/D', $column) !== 1) {
            throw new OwnershipConfigurationInvalid;
        }
    }

    /** Render native values as text while preserving string key bytes. */
    public function getValue(Grammar $grammar): string
    {
        return 'CAST('.$grammar->wrap($this->column).' AS '.(in_array($this->driver, ['mysql', 'mariadb'], true) ? 'CHAR' : 'TEXT').')';
    }
}
