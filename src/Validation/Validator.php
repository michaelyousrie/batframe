<?php

declare(strict_types=1);

namespace Batframe\Validation;

/**
 * Validates a single value against a list of {@see Rule}s. Reach it through the
 * `validate()` / `validateMany()` helpers:
 *
 *   validate($email, [Rule::required(), Rule::email()]);   // true, or throws
 *   validateMany([
 *       'x' => [Rule::string()],
 *       'y' => [Rule::integer()],
 *   ]);
 *
 * Evaluation order for a value: a `nullable` rule short-circuits a null value to
 * valid; then a `required` rule fails an empty value; then the remaining rules
 * run in listed order and every failure is collected.
 *
 * A shared singleton via {@see instance()} backs the helpers; tests can replace
 * it with {@see swap()}, mirroring the Session/Cache pattern.
 */
class Validator
{
    private static ?self $instance = null;

    /**
     * The shared instance used by the `validate()` / `validateMany()` helpers.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replace (or clear, with null) the shared instance. Intended for tests.
     */
    public static function swap(?self $validator): void
    {
        self::$instance = $validator;
    }

    /**
     * Validate a single value. Returns true when every rule passes; throws a
     * {@see ValidationException} (422) carrying the failure messages otherwise.
     *
     * @param list<Rule> $rules
     */
    public function validate(mixed $value, array $rules): bool
    {
        $errors = $this->collect($value, $rules);

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return true;
    }

    /**
     * Validate many values at once: keys are the values being validated, values
     * are their rule lists. Runs every entry (it does not stop at the first
     * failure) and aggregates all failures into one exception, keyed by entry.
     *
     * @param array<int|string, list<Rule>> $groups
     */
    public function validateMany(array $groups): bool
    {
        $errors = [];

        foreach ($groups as $value => $rules) {
            $failures = $this->collect($value, $rules);

            if ($failures !== []) {
                $errors[$value] = $failures;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return true;
    }

    /**
     * Non-throwing check: true when every rule passes.
     *
     * @param list<Rule> $rules
     */
    public function passes(mixed $value, array $rules): bool
    {
        return $this->collect($value, $rules) === [];
    }

    /**
     * The inverse of {@see passes()}.
     *
     * @param list<Rule> $rules
     */
    public function fails(mixed $value, array $rules): bool
    {
        return !$this->passes($value, $rules);
    }

    /**
     * Gather the failure messages for a value, honouring nullable/required.
     *
     * @param list<Rule> $rules
     * @return list<string>
     */
    private function collect(mixed $value, array $rules): array
    {
        foreach ($rules as $rule) {
            if ($rule->isNullable && $value === null) {
                return [];
            }
        }

        foreach ($rules as $rule) {
            if ($rule->isRequired && !$rule->passes($value)) {
                return [$rule->message];
            }
        }

        $errors = [];

        foreach ($rules as $rule) {
            if ($rule->isNullable || $rule->isRequired) {
                continue;
            }

            if ($rule->passes($value)) {
                // A passing type rule narrows the value (e.g. numeric string -> int)
                // so the rules that follow measure the value, not its text form.
                $value = $rule->coerce($value);
            } else {
                $errors[] = $rule->message;
            }
        }

        return $errors;
    }
}
