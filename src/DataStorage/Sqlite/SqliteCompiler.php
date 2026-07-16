<?php

declare(strict_types=1);

namespace Batframe\DataStorage\Sqlite;

use Batframe\DataStorage\CriteriaCompiler;
use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Is;

/**
 * Compiles criteria into SQLite.
 *
 * **Every SQLite-ism in the framework lives here.** {@see Is} says what an
 * operator means; this file is the only place that knows how SQLite says it.
 * A MySQL driver would bring its own compiler alongside its own store and
 * change nothing else.
 *
 * Two rules hold the parity with `Is::matches()` together, and both exist
 * because SQL's three-valued logic disagrees with PHP's two-valued logic the
 * moment a record is missing a field:
 *
 *   - **Every fragment evaluates to 0 or 1, never NULL.** `NULL > 18` is NULL,
 *     not false, and a NULL fragment poisons any AND, OR or NOT it is combined
 *     with. COALESCE pins the unknown answer to false, which is what
 *     `Is::matches()` says. It is also what makes NOT safe to apply directly.
 *   - **Equality goes through IS / IS NOT, not = / !=.** SQLite's IS is
 *     null-safe, so it reproduces PHP's === exactly, nulls included.
 *
 * Field access is `json_extract(data, ?)` with the path **bound**, never
 * interpolated, so a field name can never reach the SQL text. `json_extract`
 * returns NULL for a missing path, which is how a missing field becomes the
 * null that `Is` already knows what to do with.
 */
final class SqliteCompiler implements CriteriaCompiler
{
    /**
     * @return array{0: string, 1: list<mixed>}
     */
    public function compile(string $field, Is $is): array
    {
        $value = 'json_extract(data, ?)';
        $path = $this->path($field);
        $operands = $is->operands;

        return match ($is->name) {
            'equals' => $this->equals($path, $operands[0]),
            'notEquals' => $this->wrapInNot($this->equals($path, $operands[0])),

            'greaterThan' => ["COALESCE({$value} > {$this->operand($operands[0])}, 0)", [$path, $operands[0]]],
            'greaterOrEqual' => ["COALESCE({$value} >= {$this->operand($operands[0])}, 0)", [$path, $operands[0]]],
            'lessThan' => ["COALESCE({$value} < {$this->operand($operands[0])}, 0)", [$path, $operands[0]]],
            'lessOrEqual' => ["COALESCE({$value} <= {$this->operand($operands[0])}, 0)", [$path, $operands[0]]],
            'between' => [
                "COALESCE({$value} BETWEEN {$this->operand($operands[0])} AND {$this->operand($operands[1])}, 0)",
                [$path, $operands[0], $operands[1]],
            ],

            'in' => $this->in($value, $path, $operands[0]),
            'notIn' => $this->wrapInNot($this->in($value, $path, $operands[0])),

            'like' => $this->like($path, $operands[0], escaped: false),

            // The text criteria carry no pattern syntax, so whatever the caller
            // is searching for is escaped: contains('50%') looks for a literal
            // "50%" rather than quietly reading the % as "anything".
            'contains' => $this->like($path, '%' . $this->escapeLike($operands[0]) . '%', escaped: true),
            'startsWith' => $this->like($path, $this->escapeLike($operands[0]) . '%', escaped: true),
            'endsWith' => $this->like($path, '%' . $this->escapeLike($operands[0]), escaped: true),

            'null' => ["{$value} IS NULL", [$path]],
            'notNull' => ["{$value} IS NOT NULL", [$path]],

            'not' => $this->wrapInNot($this->compile($field, $operands[0])),

            default => throw new DataStorageException(
                "The SQLite driver does not know how to compile the criterion '{$is->name}'.",
            ),
        };
    }

    /**
     * Strict equality.
     *
     * `json_extract` flattens JSON's types into SQLite's: a stored `true` comes
     * back as the integer 1, so `IS ?` alone would let `find(['flag' => 1])`
     * match a record whose flag is `true` — which `Is::equals()` says is false,
     * because `1 === true` is false. Comparing `json_type` as well restores the
     * distinction the storage format threw away.
     *
     * Null is exempt: `json_extract` returns NULL both for a missing field and
     * for an explicit null, which is exactly the collapse the contract wants
     * (a missing field is null), so plain `IS NULL` is already right.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function equals(string $path, mixed $operand): array
    {
        $value = 'json_extract(data, ?)';

        if ($operand === null) {
            return ["{$value} IS NULL", [$path]];
        }

        return [
            "(json_type(data, ?) IS ? AND {$value} IS {$this->operand($operand)})",
            [$path, $this->jsonTypeOf($operand), $path, $operand],
        ];
    }

    /**
     * A LIKE against a field.
     *
     * The json_type guard is what stops LIKE reading a record's *structure*: a
     * field holding ['php'] comes back from json_extract as the text '["php"]',
     * which a bare LIKE '%php%' would happily match even though `Is` refuses
     * arrays outright.
     *
     * SQLite converts a non-text value to text for LIKE, which is how a stored
     * boolean reads as '1'/'0' and a number as its digits — the same text
     * `Is::matches()` searches.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function like(string $path, string $pattern, bool $escaped): array
    {
        $value = 'json_extract(data, ?)';
        $escape = $escaped ? " ESCAPE '\\'" : '';

        return [
            "COALESCE(json_type(data, ?) NOT IN ('array', 'object') AND {$value} LIKE ?{$escape}, 0)",
            [$path, $path, $pattern],
        ];
    }

    /**
     * Neutralise LIKE's wildcards so a caller's text is only ever text. The
     * backslash goes first, or it would escape the escapes.
     */
    private function escapeLike(string $text): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $text);
    }

    /**
     * The json_type() a value must have to be identical to this operand.
     */
    private function jsonTypeOf(mixed $value): string
    {
        return match (true) {
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value) => 'integer',
            is_float($value) => 'real',
            default => 'text',
        };
    }

    /**
     * `IN ()` is a syntax error, so an empty list becomes the constant it
     * means: nothing matches. The field goes unread, so its path binding is
     * dropped with it rather than left dangling.
     *
     * A null in the list needs lifting out: SQL's `IN` answers "unknown" for a
     * NULL operand, which COALESCE then pins to false, while
     * `in_array(..., true)` matches it. So null becomes its own IS NULL term.
     *
     * @param list<mixed> $allowed
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function in(string $value, string $path, array $allowed): array
    {
        $wantsNull = in_array(null, $allowed, true);
        $values = array_values(array_filter($allowed, static fn (mixed $v): bool => $v !== null));

        if ($values === []) {
            return $wantsNull ? ["{$value} IS NULL", [$path]] : ['0', []];
        }

        $placeholders = implode(', ', array_map($this->operand(...), $values));
        $fragment = "COALESCE({$value} IN ({$placeholders}), 0)";
        $bindings = [$path, ...$values];

        if ($wantsNull) {
            $fragment = "({$fragment} OR {$value} IS NULL)";
            $bindings[] = $path;
        }

        return [$fragment, $bindings];
    }

    /**
     * The placeholder for a bound operand.
     *
     * PDO has no float parameter type — `PDO::PARAM_STR` is the only thing a
     * float can be bound as, and it arrives as TEXT, which never compares equal
     * to the REAL that `json_extract` returns. So a float placeholder carries
     * its own cast, and `find(['price' => 9.99])` finds the record instead of
     * silently finding nothing.
     */
    private function operand(mixed $value): string
    {
        return is_float($value) ? 'CAST(? AS REAL)' : '?';
    }

    /**
     * Safe without a COALESCE because the fragment being wrapped is already
     * 0 or 1.
     *
     * @param array{0: string, 1: list<mixed>} $compiled
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function wrapInNot(array $compiled): array
    {
        [$fragment, $bindings] = $compiled;

        return ["NOT ({$fragment})", $bindings];
    }

    /**
     * A top-level field as a json path.
     *
     * The segment is quoted because `json_extract` parses its path argument: an
     * unquoted `$.a.b` reads as "b inside a", so a field literally named "a.b"
     * would go missing on this driver while the JSON driver found it by plain
     * array key. Quoting keeps a name a name.
     *
     * Public because the store needs the same path for ORDER BY, and two places
     * building json paths two ways is how they drift apart.
     */
    public function path(string $field): string
    {
        return '$."' . str_replace(['\\', '"'], ['\\\\', '\\"'], $field) . '"';
    }
}
