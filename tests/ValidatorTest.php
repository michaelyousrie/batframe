<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Validation\Rule;
use Batframe\Validation\ValidationException;
use Batframe\Validation\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    // ------------------------------------------------------------------
    // Type rules
    // ------------------------------------------------------------------

    public function test_string_rule(): void
    {
        $this->assertTrue($this->validator->passes('hello', [Rule::string()]));
        $this->assertFalse($this->validator->passes(123, [Rule::string()]));
    }

    public function test_integer_rule_accepts_int_and_integer_string(): void
    {
        $this->assertTrue($this->validator->passes(42, [Rule::integer()]));
        $this->assertTrue($this->validator->passes('123', [Rule::integer()]));
        $this->assertFalse($this->validator->passes('12.5', [Rule::integer()]));
        $this->assertFalse($this->validator->passes('abc', [Rule::integer()]));
    }

    public function test_boolean_rule(): void
    {
        foreach ([true, false, 0, 1, '0', '1', 'true', 'false'] as $ok) {
            $this->assertTrue($this->validator->passes($ok, [Rule::boolean()]), var_export($ok, true));
        }

        $this->assertFalse($this->validator->passes('nope', [Rule::boolean()]));
        $this->assertFalse($this->validator->passes(2, [Rule::boolean()]));
    }

    public function test_numeric_rule(): void
    {
        $this->assertTrue($this->validator->passes('3.14', [Rule::numeric()]));
        $this->assertTrue($this->validator->passes(10, [Rule::numeric()]));
        $this->assertFalse($this->validator->passes('ten', [Rule::numeric()]));
    }

    public function test_alphanum_rule(): void
    {
        $this->assertTrue($this->validator->passes('abc123', [Rule::alphaNum()]));
        $this->assertFalse($this->validator->passes('abc 123', [Rule::alphaNum()]));
        $this->assertFalse($this->validator->passes('a-b', [Rule::alphaNum()]));
    }

    public function test_alpha_rule(): void
    {
        $this->assertTrue($this->validator->passes('abc', [Rule::alpha()]));
        $this->assertFalse($this->validator->passes('abc1', [Rule::alpha()]));
    }

    public function test_email_rule(): void
    {
        $this->assertTrue($this->validator->passes('a@b.com', [Rule::email()]));
        $this->assertFalse($this->validator->passes('not-an-email', [Rule::email()]));
    }

    public function test_url_rule(): void
    {
        $this->assertTrue($this->validator->passes('https://example.com', [Rule::url()]));
        $this->assertFalse($this->validator->passes('example', [Rule::url()]));
    }

    // ------------------------------------------------------------------
    // Size rules
    // ------------------------------------------------------------------

    public function test_min_and_max_use_string_length(): void
    {
        // '123' has length 3 — the design's canonical example.
        $this->assertTrue($this->validator->passes('123', [Rule::min(2), Rule::max(4)]));
        $this->assertFalse($this->validator->passes('1', [Rule::min(2)]));
        $this->assertFalse($this->validator->passes('12345', [Rule::max(4)]));
    }

    public function test_size_rules_use_numeric_value_for_real_ints(): void
    {
        // A genuine int is measured by value, not length.
        $this->assertTrue($this->validator->passes(3, [Rule::between(1, 5)]));
        $this->assertFalse($this->validator->passes(9, [Rule::between(1, 5)]));
    }

    public function test_size_rules_count_arrays(): void
    {
        $this->assertTrue($this->validator->passes([1, 2, 3], [Rule::min(2), Rule::max(4)]));
        $this->assertFalse($this->validator->passes([1], [Rule::min(2)]));
    }

    public function test_between_rule(): void
    {
        $this->assertTrue($this->validator->passes('ab', [Rule::between(1, 3)]));
        $this->assertFalse($this->validator->passes('abcd', [Rule::between(1, 3)]));
    }

    // ------------------------------------------------------------------
    // Membership / pattern
    // ------------------------------------------------------------------

    public function test_in_rule(): void
    {
        $this->assertTrue($this->validator->passes('b', [Rule::in(['a', 'b', 'c'])]));
        $this->assertFalse($this->validator->passes('z', [Rule::in(['a', 'b', 'c'])]));
        // strict comparison: '1' is not 1
        $this->assertFalse($this->validator->passes('1', [Rule::in([1, 2, 3])]));
    }

    public function test_regex_rule(): void
    {
        $this->assertTrue($this->validator->passes('AB12', [Rule::regex('/^[A-Z]{2}\d{2}$/')]));
        $this->assertFalse($this->validator->passes('abcd', [Rule::regex('/^[A-Z]{2}\d{2}$/')]));
    }

    // ------------------------------------------------------------------
    // Presence / meta rules
    // ------------------------------------------------------------------

    public function test_required_fails_on_empty(): void
    {
        $this->assertFalse($this->validator->passes(null, [Rule::required()]));
        $this->assertFalse($this->validator->passes('', [Rule::required()]));
        $this->assertFalse($this->validator->passes([], [Rule::required()]));
    }

    public function test_required_passes_when_present(): void
    {
        $this->assertTrue($this->validator->passes('x', [Rule::required(), Rule::string()]));
        $this->assertTrue($this->validator->passes(0, [Rule::required()]));
        $this->assertTrue($this->validator->passes('0', [Rule::required()]));
    }

    public function test_nullable_short_circuits_null(): void
    {
        // null passes even though string() would otherwise reject it.
        $this->assertTrue($this->validator->passes(null, [Rule::nullable(), Rule::string()]));
    }

    public function test_nullable_still_applies_rules_to_non_null(): void
    {
        $this->assertFalse($this->validator->passes(123, [Rule::nullable(), Rule::string()]));
        $this->assertTrue($this->validator->passes('ok', [Rule::nullable(), Rule::string()]));
    }

    // ------------------------------------------------------------------
    // validate() / passes() / fails()
    // ------------------------------------------------------------------

    public function test_validate_returns_true_on_success(): void
    {
        $this->assertTrue($this->validator->validate('x', [Rule::string()]));
    }

    public function test_validate_throws_422_with_messages_on_failure(): void
    {
        try {
            $this->validator->validate(1, [Rule::string()]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_validate_collects_every_failing_rule(): void
    {
        try {
            // fails string() and min(3)
            $this->validator->validate(1, [Rule::string(), Rule::min(3)]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertCount(2, $e->errors());
        }
    }

    public function test_fails_is_inverse_of_passes(): void
    {
        $this->assertTrue($this->validator->fails(1, [Rule::string()]));
        $this->assertFalse($this->validator->fails('x', [Rule::string()]));
    }

    // ------------------------------------------------------------------
    // validateMany()
    // ------------------------------------------------------------------

    public function test_validate_many_returns_true_when_all_pass(): void
    {
        $this->assertTrue($this->validator->validateMany([
            'x' => [Rule::string()],
            '123' => [Rule::integer()],
        ]));
    }

    public function test_validate_many_aggregates_failures_keyed_by_entry(): void
    {
        try {
            $this->validator->validateMany([
                'x' => [Rule::string()],       // passes
                'y' => [Rule::integer()],      // fails — 'y' is not an integer
            ]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $errors = $e->errors();
            $this->assertArrayHasKey('y', $errors);
            $this->assertArrayNotHasKey('x', $errors);
        }
    }
}
