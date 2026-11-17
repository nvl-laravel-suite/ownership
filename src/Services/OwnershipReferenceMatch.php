<?php

declare(strict_types=1);

namespace Nvl\Ownership\Services;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Grammar;
use Illuminate\Database\Query\Grammars\PostgresGrammar;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Nvl\Support\Ownership\Exceptions\OwnershipConfigurationInvalid;

/**
 * Compares a table-qualified captured owner reference with one bound JSON reference.
 *
 * @internal
 */
final readonly class OwnershipReferenceMatch implements Expression
{
    /** Accept structured canonical identifiers, never caller SQL. */
    public function __construct(private string $column)
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_-]*(\.[A-Za-z_][A-Za-z0-9_-]*)*$/D', $column) !== 1) {
            throw new OwnershipConfigurationInvalid;
        }
    }

    /** Compare JSON values rather than their text, with exactly one binding. */
    public function getValue(Grammar $grammar): string
    {
        return match (true) {
            $grammar instanceof PostgresGrammar => $grammar->wrap($this->column).'::jsonb = ?::jsonb',
            $grammar instanceof SQLiteGrammar => 'json('.$grammar->wrap($this->column).') = json(?)',
            default => throw new OwnershipConfigurationInvalid('Unsupported ownership query driver.'),
        };
    }
}
