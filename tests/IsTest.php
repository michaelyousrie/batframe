<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\DataStorage\Is;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Is defines what an operator *means*. Nothing here knows about SQL — that is
 * a driver's business, and it is tested in SqliteCompilerTest.
 */
final class IsTest extends TestCase
{
    // ------------------------------------------------------------------
    // Equality
    // ------------------------------------------------------------------

    public function test_equals_matches_identically(): void
    {
        $is = Is::equals('Michael');

        $this->assertTrue($is->matches('Michael'));
        $this->assertFalse($is->matches('F0rty'));

        // Strict: a numeric string is not the number.
        $this->assertFalse(Is::equals(18)->matches('18'));
        $this->assertTrue(Is::equals(18)->matches(18));

        // Null is a value like any other.
        $this->assertTrue(Is::equals(null)->matches(null));
    }

    public function test_not_equals(): void
    {
        $is = Is::notEquals('Michael');

        $this->assertTrue($is->matches('F0rty'));
        $this->assertFalse($is->matches('Michael'));

        // A record missing the field is not equal to 'Michael'.
        $this->assertTrue($is->matches(null));
    }

    // ------------------------------------------------------------------
    // Ordering
    // ------------------------------------------------------------------

    public function test_greater_than(): void
    {
        $is = Is::greaterThan(18);

        $this->assertTrue($is->matches(19));
        $this->assertFalse($is->matches(18));
    }

    public function test_greater_or_equal(): void
    {
        $is = Is::greaterOrEqual(18);

        $this->assertTrue($is->matches(18));
        $this->assertFalse($is->matches(17));
    }

    public function test_less_than(): void
    {
        $is = Is::lessThan(18);

        $this->assertTrue($is->matches(17));
        $this->assertFalse($is->matches(18));
    }

    public function test_less_or_equal(): void
    {
        $is = Is::lessOrEqual(18);

        $this->assertTrue($is->matches(18));
        $this->assertFalse($is->matches(19));
    }

    public function test_null_never_satisfies_an_ordering_comparison(): void
    {
        // PHP alone would say null < 18 is true. The contract says a record
        // missing the field is not less than anything, and every driver has to
        // agree — this is the single most likely place for two drivers to drift.
        $this->assertFalse(Is::lessThan(18)->matches(null));
        $this->assertFalse(Is::lessOrEqual(18)->matches(null));
        $this->assertFalse(Is::greaterThan(18)->matches(null));
        $this->assertFalse(Is::greaterOrEqual(18)->matches(null));
        $this->assertFalse(Is::between(18, 30)->matches(null));
    }

    public function test_between_is_inclusive(): void
    {
        $is = Is::between(18, 30);

        $this->assertTrue($is->matches(18));
        $this->assertTrue($is->matches(30));
        $this->assertFalse($is->matches(31));
    }

    // ------------------------------------------------------------------
    // Membership
    // ------------------------------------------------------------------

    public function test_in(): void
    {
        $is = Is::in(['admin', 'owner']);

        $this->assertTrue($is->matches('admin'));
        $this->assertFalse($is->matches('guest'));

        // Membership is strict, like equals.
        $this->assertFalse(Is::in([1, 2])->matches('1'));
    }

    public function test_not_in(): void
    {
        $is = Is::notIn(['admin', 'owner']);

        $this->assertTrue($is->matches('guest'));
        $this->assertFalse($is->matches('admin'));

        // A missing field is in no list, so it is notIn every list.
        $this->assertTrue($is->matches(null));
    }

    public function test_empty_lists(): void
    {
        $this->assertFalse(Is::in([])->matches('anything'));
        $this->assertTrue(Is::notIn([])->matches('anything'));
    }

    // ------------------------------------------------------------------
    // Pattern / presence
    // ------------------------------------------------------------------

    public function test_like_uses_sql_wildcards_and_ignores_case(): void
    {
        $is = Is::like('%php%');

        $this->assertTrue($is->matches('loves php'));
        $this->assertTrue($is->matches('loves PHP'));
        $this->assertFalse($is->matches('loves ruby'));
        $this->assertFalse($is->matches(null));

        // _ is the single-character wildcard.
        $this->assertTrue(Is::like('b_t')->matches('bat'));
        $this->assertFalse(Is::like('b_t')->matches('boat'));

        // Regex metacharacters in the pattern are literal.
        $this->assertTrue(Is::like('%a.b%')->matches('x a.b y'));
        $this->assertFalse(Is::like('%a.b%')->matches('x axb y'));
    }

    public function test_like_with_no_wildcard_is_an_exact_match(): void
    {
        // The trap this whole family of criteria exists to route around: LIKE
        // anchors, so like('testing') is a slower Is::equals('testing') and
        // will not find 'testingName'.
        $this->assertFalse(Is::like('testing')->matches('testingName'));
        $this->assertTrue(Is::like('testing')->matches('testing'));
        $this->assertTrue(Is::like('testing%')->matches('testingName'));
    }

    public function test_contains(): void
    {
        $is = Is::contains('esting');

        $this->assertTrue($is->matches('testingName'));
        $this->assertTrue(Is::contains('ESTING')->matches('testingName'));
        $this->assertFalse($is->matches('nope'));
        $this->assertFalse($is->matches(null));

        // Everything matches the empty string, as LIKE '%%' would.
        $this->assertTrue(Is::contains('')->matches('anything'));
    }

    public function test_starts_with_and_ends_with(): void
    {
        $this->assertTrue(Is::startsWith('testing')->matches('testingName'));
        $this->assertTrue(Is::startsWith('TESTING')->matches('testingName'));
        $this->assertFalse(Is::startsWith('Name')->matches('testingName'));
        $this->assertFalse(Is::startsWith('x')->matches(null));

        $this->assertTrue(Is::endsWith('Name')->matches('testingName'));
        $this->assertTrue(Is::endsWith('name')->matches('testingName'));
        $this->assertFalse(Is::endsWith('testing')->matches('testingName'));
        $this->assertFalse(Is::endsWith('x')->matches(null));

        // A needle longer than the value cannot be its ending.
        $this->assertFalse(Is::endsWith('much longer than this')->matches('short'));
        $this->assertTrue(Is::endsWith('')->matches('anything'));
    }

    public function test_text_search_has_no_wildcards(): void
    {
        // % and _ are characters here, not syntax.
        $this->assertTrue(Is::contains('50%')->matches('50% off'));
        $this->assertFalse(Is::contains('50%')->matches('50 off'));

        $this->assertTrue(Is::contains('a_b')->matches('x a_b y'));
        $this->assertFalse(Is::contains('a_b')->matches('x axb y'));

        $this->assertTrue(Is::startsWith('%')->matches('%discount'));
        $this->assertFalse(Is::startsWith('%')->matches('discount'));
    }

    public function test_text_criteria_read_a_boolean_as_one_or_zero(): void
    {
        // PHP would make (string) false the empty string; a driver storing
        // records as JSON sees 0. The contract follows the driver.
        $this->assertTrue(Is::contains('0')->matches(false));
        $this->assertTrue(Is::contains('1')->matches(true));
        $this->assertTrue(Is::like('0')->matches(false));
        $this->assertFalse(Is::contains('1')->matches(false));
    }

    public function test_text_criteria_refuse_structure(): void
    {
        foreach ([Is::contains('php'), Is::startsWith('php'), Is::endsWith('php'), Is::like('%php%')] as $is) {
            $this->assertFalse($is->matches(['php', 'web']), "{$is->name} matched an array");
        }
    }

    public function test_null_and_not_null(): void
    {
        $this->assertTrue(Is::null()->matches(null));
        $this->assertFalse(Is::null()->matches('x'));

        $this->assertTrue(Is::notNull()->matches('x'));
        $this->assertFalse(Is::notNull()->matches(null));
    }

    // ------------------------------------------------------------------
    // Negation
    // ------------------------------------------------------------------

    public function test_not_wraps_a_scalar_as_inequality(): void
    {
        $is = Is::not('Michael');

        $this->assertTrue($is->matches('F0rty'));
        $this->assertFalse($is->matches('Michael'));
    }

    public function test_not_composes_over_another_is(): void
    {
        $is = Is::not(Is::like('%php%'));

        $this->assertTrue($is->matches('loves ruby'));
        $this->assertFalse($is->matches('loves php'));
    }

    public function test_not_of_a_missing_field_matches(): void
    {
        // !(null > 18) is true, because null > 18 is false.
        $this->assertTrue(Is::not(Is::greaterThan(18))->matches(null));
    }

    // ------------------------------------------------------------------
    // Shape a compiler relies on
    // ------------------------------------------------------------------

    public function test_operands_mirror_the_factory_arguments(): void
    {
        // A compiler reads these positionally, so their shape is contract.
        $this->assertSame('equals', Is::equals('Michael')->name);
        $this->assertSame(['Michael'], Is::equals('Michael')->operands);

        $this->assertSame([18, 30], Is::between(18, 30)->operands);
        $this->assertSame([[]], Is::in([])->operands);
        $this->assertSame([['admin', 'owner']], Is::in(['admin', 'owner'])->operands);
        $this->assertSame([], Is::null()->operands);
        $this->assertSame(['%php%'], Is::like('%php%')->operands);
    }

    public function test_not_always_carries_an_is_as_its_operand(): void
    {
        // A compiler should only ever have to handle one shape here, so the
        // scalar form is normalised at construction.
        $fromScalar = Is::not('Michael');
        $this->assertInstanceOf(Is::class, $fromScalar->operands[0]);
        $this->assertSame('equals', $fromScalar->operands[0]->name);

        $fromIs = Is::not(Is::like('%php%'));
        $this->assertSame('like', $fromIs->operands[0]->name);
    }

    public function test_factories_are_the_only_way_in(): void
    {
        $reflection = new ReflectionClass(Is::class);

        $this->assertTrue($reflection->getConstructor()->isPrivate());
        $this->assertTrue($reflection->isFinal());
    }
}
