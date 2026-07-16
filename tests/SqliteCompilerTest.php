<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Is;
use Batframe\DataStorage\Sqlite\SqliteCompiler;
use Batframe\DataStorage\Sqlite\SqliteStore;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SqliteCompilerTest extends TestCase
{
    private SqliteCompiler $compiler;

    protected function setUp(): void
    {
        $this->compiler = new SqliteCompiler();
    }

    // ------------------------------------------------------------------
    // Fragments
    // ------------------------------------------------------------------

    public function test_equality_is_strict_about_type_as_well_as_value(): void
    {
        // json_extract flattens a stored `true` to the integer 1, so comparing
        // the value alone would let 1 match true. json_type restores what the
        // storage format threw away.
        $this->assertSame(
            ['(json_type(data, ?) IS ? AND json_extract(data, ?) IS ?)', ['$."name"', 'text', '$."name"', 'Michael']],
            $this->compiler->compile('name', Is::equals('Michael')),
        );

        $this->assertSame(
            ['(json_type(data, ?) IS ? AND json_extract(data, ?) IS ?)', ['$."flag"', 'true', '$."flag"', true]],
            $this->compiler->compile('flag', Is::equals(true)),
        );

        $this->assertSame(
            ['NOT ((json_type(data, ?) IS ? AND json_extract(data, ?) IS ?))', ['$."age"', 'integer', '$."age"', 18]],
            $this->compiler->compile('age', Is::notEquals(18)),
        );
    }

    public function test_equality_with_null_needs_no_type_guard(): void
    {
        // json_extract returns NULL for a missing field and for an explicit
        // null alike — which is exactly the collapse the contract asks for.
        $this->assertSame(
            ['json_extract(data, ?) IS NULL', ['$."name"']],
            $this->compiler->compile('name', Is::equals(null)),
        );
    }

    public function test_a_float_operand_carries_its_own_cast(): void
    {
        // PDO has no float parameter type, so an uncast float arrives as TEXT
        // and never equals the REAL json_extract returns.
        $this->assertSame(
            ['(json_type(data, ?) IS ? AND json_extract(data, ?) IS CAST(? AS REAL))', ['$."price"', 'real', '$."price"', 9.99]],
            $this->compiler->compile('price', Is::equals(9.99)),
        );

        $this->assertSame(
            ['COALESCE(json_extract(data, ?) > CAST(? AS REAL), 0)', ['$."price"', 9.99]],
            $this->compiler->compile('price', Is::greaterThan(9.99)),
        );
    }

    public function test_ordering_comparisons_coalesce_null_to_false(): void
    {
        // NULL > 18 is NULL, not false, and a NULL fragment poisons every AND,
        // OR and NOT it meets.
        $this->assertSame(
            ['COALESCE(json_extract(data, ?) > ?, 0)', ['$."age"', 18]],
            $this->compiler->compile('age', Is::greaterThan(18)),
        );

        $this->assertSame(
            ['COALESCE(json_extract(data, ?) >= ?, 0)', ['$."age"', 18]],
            $this->compiler->compile('age', Is::greaterOrEqual(18)),
        );

        $this->assertSame(
            ['COALESCE(json_extract(data, ?) < ?, 0)', ['$."age"', 18]],
            $this->compiler->compile('age', Is::lessThan(18)),
        );

        $this->assertSame(
            ['COALESCE(json_extract(data, ?) <= ?, 0)', ['$."age"', 18]],
            $this->compiler->compile('age', Is::lessOrEqual(18)),
        );

        $this->assertSame(
            ['COALESCE(json_extract(data, ?) BETWEEN ? AND ?, 0)', ['$."age"', 18, 30]],
            $this->compiler->compile('age', Is::between(18, 30)),
        );
    }

    public function test_membership(): void
    {
        $this->assertSame(
            ['COALESCE(json_extract(data, ?) IN (?, ?), 0)', ['$."role"', 'admin', 'owner']],
            $this->compiler->compile('role', Is::in(['admin', 'owner'])),
        );

        $this->assertSame(
            ['NOT (COALESCE(json_extract(data, ?) IN (?, ?), 0))', ['$."role"', 'admin', 'owner']],
            $this->compiler->compile('role', Is::notIn(['admin', 'owner'])),
        );
    }

    public function test_a_null_in_the_list_becomes_its_own_term(): void
    {
        // SQL's IN answers "unknown" for a NULL operand, which COALESCE would
        // then pin to false — while in_array(..., true) matches it.
        $this->assertSame(
            ['(COALESCE(json_extract(data, ?) IN (?), 0) OR json_extract(data, ?) IS NULL)', ['$."age"', 17, '$."age"']],
            $this->compiler->compile('age', Is::in([17, null])),
        );

        $this->assertSame(
            ['json_extract(data, ?) IS NULL', ['$."age"']],
            $this->compiler->compile('age', Is::in([null])),
        );
    }

    public function test_empty_in_list_collapses_and_drops_its_bindings(): void
    {
        // "IN ()" is a syntax error, so the fragment becomes the constant it
        // means. The field is never looked at, so its path binding must go too
        // — keeping it would leave an orphaned placeholder that only blows up
        // at execute() time.
        $this->assertSame(['0', []], $this->compiler->compile('role', Is::in([])));
        $this->assertSame(['NOT (0)', []], $this->compiler->compile('role', Is::notIn([])));
    }

    public function test_like_refuses_to_look_at_structure(): void
    {
        // Without the guard, LIKE would match a record's serialisation: a tags
        // field of ['php'] reads as the text '["php"]'.
        $this->assertSame(
            [
                "COALESCE(json_type(data, ?) NOT IN ('array', 'object') AND json_extract(data, ?) LIKE ?, 0)",
                ['$."bio"', '$."bio"', '%php%'],
            ],
            $this->compiler->compile('bio', Is::like('%php%')),
        );
    }

    public function test_presence(): void
    {
        $this->assertSame(
            ['json_extract(data, ?) IS NULL', ['$."name"']],
            $this->compiler->compile('name', Is::null()),
        );

        $this->assertSame(
            ['json_extract(data, ?) IS NOT NULL', ['$."name"']],
            $this->compiler->compile('name', Is::notNull()),
        );
    }

    public function test_negation_wraps_the_compiled_inner_criterion(): void
    {
        // Every fragment is already 0 or 1, so NOT can never produce NULL.
        $this->assertSame(
            [
                "NOT (COALESCE(json_type(data, ?) NOT IN ('array', 'object') AND json_extract(data, ?) LIKE ?, 0))",
                ['$."bio"', '$."bio"', '%php%'],
            ],
            $this->compiler->compile('bio', Is::not(Is::like('%php%'))),
        );
    }

    // ------------------------------------------------------------------
    // Paths
    // ------------------------------------------------------------------

    public function test_a_field_name_is_quoted_into_the_path(): void
    {
        $this->assertSame('$."name"', $this->compiler->path('name'));

        // json_extract parses its path argument, so an unquoted $.a.b would
        // read as "b inside a" and a field actually named "a.b" would vanish.
        $this->assertSame('$."a.b"', $this->compiler->path('a.b'));
        $this->assertSame('$."a[0]"', $this->compiler->path('a[0]'));
        $this->assertSame('$."say \"hi\""', $this->compiler->path('say "hi"'));
    }

    public function test_an_operator_this_driver_cannot_express_is_loud(): void
    {
        // Simulates someone adding a factory to Is without teaching this
        // compiler about it. Silently matching nothing would be far worse.
        // (Use a name Is really does not have — this test previously used
        // 'startsWith', and started failing the day that became real.)
        try {
            $this->compiler->compile('name', $this->criterionNamed('matchesRegex'));
            $this->fail('Expected DataStorageException');
        } catch (DataStorageException $e) {
            $this->assertStringContainsString('matchesRegex', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // Parity with Is — the fragments have to mean what Is says they mean
    // ------------------------------------------------------------------

    public function test_every_criterion_answers_exactly_what_is_matches_says(): void
    {
        // Driven through a real SqliteStore rather than a hand-rolled PDO
        // connection: binding is half of what makes a fragment mean anything
        // (a float bound as TEXT matches nothing), so a harness that binds by
        // its own rules would pass while the driver was broken.
        $record = [
            'name' => 'Michael',
            'age' => 36,
            'price' => 9.99,
            'role' => 'admin',
            'bio' => 'loves PHP',
            'tag' => 'bat',
            'flag' => true,
            'off' => false,
            'nil' => null,
            'agetext' => '36',
            'a.b' => 'dotted',
        ];

        // 'absent' is deliberately not a key: a missing field is null, and null
        // is where SQL's three-valued logic and PHP's two-valued logic drift.
        $cases = [
            ['name', Is::equals('Michael')],
            ['name', Is::equals('F0rty')],
            ['absent', Is::equals(null)],
            ['nil', Is::equals(null)],
            ['flag', Is::equals(true)],
            ['flag', Is::equals(1)],
            ['off', Is::equals(false)],
            ['age', Is::equals(36)],
            ['agetext', Is::equals(36)],
            ['price', Is::equals(9.99)],
            ['price', Is::equals(9.98)],
            ['a.b', Is::equals('dotted')],
            ['name', Is::notEquals('Michael')],
            ['absent', Is::notEquals('Michael')],
            ['flag', Is::notEquals(1)],
            ['age', Is::greaterThan(18)],
            ['price', Is::greaterThan(9.0)],
            ['price', Is::lessThan(10.0)],
            ['absent', Is::greaterThan(18)],
            ['absent', Is::greaterOrEqual(18)],
            ['absent', Is::lessThan(18)],
            ['absent', Is::lessOrEqual(18)],
            ['age', Is::greaterOrEqual(36)],
            ['age', Is::lessOrEqual(36)],
            ['age', Is::between(18, 36)],
            ['price', Is::between(9.0, 10.0)],
            ['absent', Is::between(18, 36)],
            ['role', Is::in(['admin', 'owner'])],
            ['role', Is::in([])],
            ['age', Is::in([36, null])],
            ['absent', Is::in([36, null])],
            ['absent', Is::in([36])],
            ['role', Is::notIn(['admin'])],
            ['absent', Is::notIn(['admin'])],
            ['absent', Is::notIn([null])],
            ['bio', Is::like('%php%')],
            ['bio', Is::like('%PHP%')],
            ['bio', Is::like('loves')],
            ['absent', Is::like('%php%')],
            ['nil', Is::like('%php%')],
            ['tag', Is::like('b_t')],
            ['age', Is::like('%3%')],
            ['flag', Is::like('1')],
            ['off', Is::like('0')],
            ['bio', Is::contains('PHP')],
            ['bio', Is::contains('loves')],
            ['bio', Is::contains('ruby')],
            ['bio', Is::contains('%')],
            ['bio', Is::contains('_')],
            ['bio', Is::contains('')],
            ['absent', Is::contains('x')],
            ['nil', Is::contains('x')],
            ['flag', Is::contains('1')],
            ['off', Is::contains('0')],
            ['age', Is::contains('6')],
            ['price', Is::contains('.99')],
            ['bio', Is::startsWith('loves')],
            ['bio', Is::startsWith('LOVES')],
            ['bio', Is::startsWith('php')],
            ['bio', Is::startsWith('')],
            ['absent', Is::startsWith('x')],
            ['bio', Is::endsWith('PHP')],
            ['bio', Is::endsWith('loves')],
            ['bio', Is::endsWith('')],
            ['bio', Is::endsWith('loves PHP and more')],
            ['absent', Is::endsWith('x')],
            ['absent', Is::null()],
            ['nil', Is::null()],
            ['name', Is::null()],
            ['name', Is::notNull()],
            ['absent', Is::notNull()],
            ['name', Is::not('Michael')],
            ['bio', Is::not(Is::like('%php%'))],
            ['absent', Is::not(Is::greaterThan(18))],
        ];

        $store = new SqliteStore(':memory:', fn (): int => 1_000_000);
        $store->insert('t', $record);

        foreach ($cases as [$field, $is]) {
            $inSqlite = $store->exists('t', [$field => $is]);
            $inPhp = $is->matches($record[$field] ?? null);

            $this->assertSame(
                $inPhp,
                $inSqlite,
                "{$is->name} on '{$field}': the SQLite driver disagrees with Is::matches()",
            );
        }
    }

    /**
     * Build an Is with an arbitrary operator name, standing in for a factory
     * this compiler has not been taught yet.
     */
    private function criterionNamed(string $name): Is
    {
        $reflection = new ReflectionClass(Is::class);
        $is = $reflection->newInstanceWithoutConstructor();

        $constructor = $reflection->getConstructor();
        $constructor->setAccessible(true);
        $constructor->invoke($is, $name, ['x'], static fn (mixed $v): bool => true);

        return $is;
    }
}
