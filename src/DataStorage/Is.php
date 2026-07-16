<?php

declare(strict_types=1);

namespace Batframe\DataStorage;

use Closure;

/**
 * A single criterion, built through a static factory so the call site stays
 * type-safe with no magic strings:
 *
 *   db('users')->find(['age' => Is::greaterThan(18)]);
 *   db('users')->find(['role' => Is::in(['admin', 'owner'])]);
 *
 * **This class never learns a query language.** It describes intent — an
 * operator name, its operands, and a predicate — and nothing else. Translating
 * that intent into SQL, or into whatever a future driver speaks, is the
 * driver's job, via its own {@see CriteriaCompiler}. That is what lets someone
 * add MySQL or Redis by writing new files instead of editing this one.
 *
 * The predicate is not merely the in-PHP implementation: it is the **canonical
 * definition** of what the operator means. A driver that answers differently
 * than the predicate is wrong, and `tests/StoreContractTestCase.php` is where
 * that gets caught.
 *
 * Two semantics are worth stating outright, because they are the ones drivers
 * get wrong:
 *
 *   - **A missing field is null.** Both the value passed here and a driver's
 *     field lookup must agree on that, so the operators only have to decide
 *     about null once.
 *   - **Null never satisfies an ordering comparison.** PHP would happily tell
 *     you `null < 18`; the contract says it does not, because a record missing
 *     the field is not "less than" anything.
 */
final class Is
{
    /**
     * @param list<mixed>          $operands Positional, mirroring the factory's arguments.
     * @param Closure(mixed): bool $predicate
     */
    private function __construct(
        public readonly string $name,
        public readonly array $operands,
        public readonly Closure $predicate,
    ) {
    }

    /**
     * Run this criterion's predicate against a value. This is the reference
     * answer every driver must reproduce.
     */
    public function matches(mixed $value): bool
    {
        return ($this->predicate)($value);
    }

    // ------------------------------------------------------------------
    // Equality
    // ------------------------------------------------------------------

    public static function equals(mixed $value): self
    {
        self::guardScalar($value, 'equals');

        return new self('equals', [$value], static fn (mixed $v): bool => $v === $value);
    }

    public static function notEquals(mixed $value): self
    {
        self::guardScalar($value, 'notEquals');

        return new self('notEquals', [$value], static fn (mixed $v): bool => $v !== $value);
    }

    // ------------------------------------------------------------------
    // Ordering
    // ------------------------------------------------------------------

    public static function greaterThan(mixed $value): self
    {
        return new self('greaterThan', [$value], static fn (mixed $v): bool => $v !== null && $v > $value);
    }

    public static function greaterOrEqual(mixed $value): self
    {
        return new self('greaterOrEqual', [$value], static fn (mixed $v): bool => $v !== null && $v >= $value);
    }

    public static function lessThan(mixed $value): self
    {
        return new self('lessThan', [$value], static fn (mixed $v): bool => $v !== null && $v < $value);
    }

    public static function lessOrEqual(mixed $value): self
    {
        return new self('lessOrEqual', [$value], static fn (mixed $v): bool => $v !== null && $v <= $value);
    }

    public static function between(mixed $min, mixed $max): self
    {
        return new self(
            'between',
            [$min, $max],
            static fn (mixed $v): bool => $v !== null && $v >= $min && $v <= $max,
        );
    }

    // ------------------------------------------------------------------
    // Membership
    // ------------------------------------------------------------------

    /**
     * @param list<mixed> $allowed
     */
    public static function in(array $allowed): self
    {
        self::guardScalars($allowed, 'in');

        return new self('in', [$allowed], static fn (mixed $v): bool => in_array($v, $allowed, true));
    }

    /**
     * @param list<mixed> $disallowed
     */
    public static function notIn(array $disallowed): self
    {
        self::guardScalars($disallowed, 'notIn');

        return new self('notIn', [$disallowed], static fn (mixed $v): bool => !in_array($v, $disallowed, true));
    }

    // ------------------------------------------------------------------
    // Pattern / presence
    // ------------------------------------------------------------------

    /**
     * Does the value contain this text? Case-insensitive, and there is no
     * pattern syntax: a % or _ in $text is just a character.
     *
     * This is what people mean nine times in ten when they reach for
     * {@see like()}, without the trap that `like('testing')` matches only the
     * whole word.
     */
    public static function contains(string $text): self
    {
        return new self(
            'contains',
            [$text],
            static function (mixed $v) use ($text): bool {
                $subject = self::textOf($v);

                return $subject !== null && stripos($subject, $text) !== false;
            },
        );
    }

    /**
     * Case-insensitive, no pattern syntax.
     */
    public static function startsWith(string $text): self
    {
        return new self(
            'startsWith',
            [$text],
            static function (mixed $v) use ($text): bool {
                $subject = self::textOf($v);

                return $subject !== null && strncasecmp($subject, $text, strlen($text)) === 0;
            },
        );
    }

    /**
     * Case-insensitive, no pattern syntax.
     */
    public static function endsWith(string $text): self
    {
        return new self(
            'endsWith',
            [$text],
            static function (mixed $v) use ($text): bool {
                $subject = self::textOf($v);

                if ($subject === null || strlen($subject) < strlen($text)) {
                    return false;
                }

                return $text === '' || strcasecmp(substr($subject, -strlen($text)), $text) === 0;
            },
        );
    }

    /**
     * SQL LIKE semantics: % is any run of characters, _ is exactly one, and
     * matching is case-insensitive.
     *
     * **A pattern with no wildcard is an exact match**, so `like('testing')`
     * will not find 'testingName' — that is `like('testing%')`, or better,
     * {@see startsWith()}. Reach for this only when you want a real pattern.
     */
    public static function like(string $pattern): self
    {
        return new self(
            'like',
            [$pattern],
            static function (mixed $v) use ($pattern): bool {
                $subject = self::textOf($v);

                return $subject !== null && preg_match(self::likeToRegex($pattern), $subject) === 1;
            },
        );
    }

    public static function null(): self
    {
        return new self('null', [], static fn (mixed $v): bool => $v === null);
    }

    public static function notNull(): self
    {
        return new self('notNull', [], static fn (mixed $v): bool => $v !== null);
    }

    // ------------------------------------------------------------------
    // Negation
    // ------------------------------------------------------------------

    /**
     * Negate a scalar (which reads as "not equal to") or any other criterion,
     * so Is::not(Is::like('%php%')) composes. The operand is always an Is by
     * the time a compiler sees it, so a compiler only has to handle one shape.
     */
    public static function not(mixed $value): self
    {
        $inner = $value instanceof self ? $value : self::equals($value);

        return new self('not', [$inner], static fn (mixed $v): bool => !$inner->matches($v));
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Criteria compare scalars.
     *
     * A record may hold anything JSON can hold, but *comparing* a whole array
     * or object is out of scope, and it is refused here rather than left to
     * each driver. Otherwise a driver holding records as PHP arrays would
     * compare them structurally while a driver holding them as JSON text
     * compared their serialisation — two different answers to one question,
     * with nobody told which they were getting.
     *
     * @throws DataStorageException
     */
    private static function guardScalar(mixed $value, string $factory): void
    {
        if ($value !== null && !is_scalar($value)) {
            $given = get_debug_type($value);

            throw new DataStorageException(
                "Is::{$factory}() compares scalar values, but was given {$given}. "
                . 'Compare a field inside the structure instead.',
            );
        }
    }

    /**
     * @param list<mixed> $values
     *
     * @throws DataStorageException
     */
    private static function guardScalars(array $values, string $factory): void
    {
        foreach ($values as $value) {
            self::guardScalar($value, $factory);
        }
    }

    /**
     * A value as the text a text criterion searches, or null for something
     * there is no sensible text for.
     *
     * A boolean is '1' or '0' rather than PHP's '1' and '', because that is
     * what a driver holding records as JSON sees once JSON's `true` has landed
     * in its own types — and a text search must not find different things
     * depending on where the record was stored.
     *
     * Comparing text against a whole array or object is meaningless, so those
     * match nothing rather than matching their own serialisation.
     */
    private static function textOf(mixed $value): ?string
    {
        return match (true) {
            $value === null, is_array($value) => null,
            is_bool($value) => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    /**
     * Translate a LIKE pattern to a regex. preg_quote leaves % and _ alone,
     * which is exactly what lets the wildcards survive while every regex
     * metacharacter in the pattern is neutered.
     */
    private static function likeToRegex(string $pattern): string
    {
        $quoted = preg_quote($pattern, '/');

        return '/^' . str_replace(['%', '_'], ['.*', '.'], $quoted) . '$/i';
    }
}
