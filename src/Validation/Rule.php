<?php

declare(strict_types=1);

namespace Batframe\Validation;

use Closure;

/**
 * A single validation rule, built through a static factory so the call site
 * stays type-safe with no magic strings:
 *
 *   validate($email, [Rule::required(), Rule::email()]);
 *   validate('123', [Rule::min(2), Rule::max(4)]);
 *
 * Each rule carries a predicate, a human-readable failure message, and two
 * meta-flags the {@see Validator} treats specially: `isNullable` (a null value
 * short-circuits to valid) and `isRequired` (an empty value fails outright).
 */
final class Rule
{
    /**
     * @param Closure(mixed): bool $predicate
     */
    private function __construct(
        public readonly string $name,
        public readonly Closure $predicate,
        public readonly string $message,
        public readonly bool $isNullable = false,
        public readonly bool $isRequired = false,
    ) {
    }

    /**
     * Run this rule's predicate against a value.
     */
    public function passes(mixed $value): bool
    {
        return ($this->predicate)($value);
    }

    // ------------------------------------------------------------------
    // Presence / meta
    // ------------------------------------------------------------------

    public static function required(): self
    {
        return new self(
            'required',
            static fn (mixed $v): bool => !self::isEmpty($v),
            'This value is required.',
            isRequired: true,
        );
    }

    public static function nullable(): self
    {
        return new self(
            'nullable',
            static fn (mixed $v): bool => true,
            '',
            isNullable: true,
        );
    }

    // ------------------------------------------------------------------
    // Types
    // ------------------------------------------------------------------

    public static function string(): self
    {
        return new self(
            'string',
            static fn (mixed $v): bool => is_string($v),
            'This value must be a string.',
        );
    }

    public static function integer(): self
    {
        return new self(
            'integer',
            static fn (mixed $v): bool => is_int($v)
                || (is_string($v) && filter_var($v, FILTER_VALIDATE_INT) !== false),
            'This value must be an integer.',
        );
    }

    public static function boolean(): self
    {
        return new self(
            'boolean',
            static fn (mixed $v): bool => in_array($v, [true, false, 0, 1, '0', '1', 'true', 'false'], true),
            'This value must be a boolean.',
        );
    }

    public static function numeric(): self
    {
        return new self(
            'numeric',
            static fn (mixed $v): bool => is_numeric($v),
            'This value must be numeric.',
        );
    }

    // ------------------------------------------------------------------
    // String content
    // ------------------------------------------------------------------

    public static function alphaNum(): self
    {
        return new self(
            'alphaNum',
            static fn (mixed $v): bool => is_string($v) && $v !== '' && ctype_alnum($v),
            'This value must contain only letters and numbers.',
        );
    }

    public static function alpha(): self
    {
        return new self(
            'alpha',
            static fn (mixed $v): bool => is_string($v) && $v !== '' && ctype_alpha($v),
            'This value must contain only letters.',
        );
    }

    public static function email(): self
    {
        return new self(
            'email',
            static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_EMAIL) !== false,
            'This value must be a valid email address.',
        );
    }

    public static function url(): self
    {
        return new self(
            'url',
            static fn (mixed $v): bool => is_string($v) && filter_var($v, FILTER_VALIDATE_URL) !== false,
            'This value must be a valid URL.',
        );
    }

    // ------------------------------------------------------------------
    // Size (length for strings, count for arrays, value for int/float)
    // ------------------------------------------------------------------

    public static function min(int $min): self
    {
        return new self(
            'min',
            static fn (mixed $v): bool => self::size($v) >= $min,
            "This value must be at least {$min}.",
        );
    }

    public static function max(int $max): self
    {
        return new self(
            'max',
            static fn (mixed $v): bool => self::size($v) <= $max,
            "This value must be at most {$max}.",
        );
    }

    public static function between(int $min, int $max): self
    {
        return new self(
            'between',
            static function (mixed $v) use ($min, $max): bool {
                $size = self::size($v);

                return $size >= $min && $size <= $max;
            },
            "This value must be between {$min} and {$max}.",
        );
    }

    // ------------------------------------------------------------------
    // Membership / pattern
    // ------------------------------------------------------------------

    /**
     * @param list<mixed> $allowed
     */
    public static function in(array $allowed): self
    {
        return new self(
            'in',
            static fn (mixed $v): bool => in_array($v, $allowed, true),
            'This value is not one of the allowed values.',
        );
    }

    public static function regex(string $pattern): self
    {
        return new self(
            'regex',
            static fn (mixed $v): bool => is_string($v) && preg_match($pattern, $v) === 1,
            'This value has an invalid format.',
        );
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * The "size" of a value: numeric value for ints/floats, character length
     * for strings, element count for arrays. A numeric string is a string, so
     * it is measured by length — cast to compare by value.
     */
    private static function size(mixed $value): int|float
    {
        if (is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_array($value)) {
            return count($value);
        }

        if (is_string($value)) {
            return mb_strlen($value);
        }

        return 0;
    }

    /**
     * Empty means null, the empty string, or the empty array.
     */
    private static function isEmpty(mixed $value): bool
    {
        return $value === null || $value === '' || $value === [];
    }
}
