<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Batframe;
use Batframe\Http\Request;
use Batframe\Http\Response;
use Batframe\Validation\Rule;
use Batframe\Validation\ValidationException;
use Batframe\Validation\Validator;
use Batframe\View\ViewEngine;
use PHPUnit\Framework\TestCase;

/**
 * A no-op view engine so this test never touches the filesystem.
 */
final class SilentViewEngine implements ViewEngine
{
    public function render(string $template, array $data = []): string
    {
        return $template;
    }

    public function exists(string $template): bool
    {
        return false;
    }
}

/**
 * A controller whose one endpoint validates its input, so we can prove the
 * ValidationException flows through Batframe's error pipeline as a 422.
 */
class ValidatorController extends Batframe
{
    public function getCheck(): array
    {
        validate(request('name'), [Rule::required(), Rule::string()]);

        return ['ok' => true];
    }
}

final class ValidatorHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        Validator::swap(null);
        Request::swap(null);
    }

    public function test_helper_no_args_returns_validator_instance(): void
    {
        $this->assertInstanceOf(Validator::class, validate());
    }

    public function test_helper_validates_and_returns_true(): void
    {
        $this->assertTrue(validate('hello', [Rule::string()]));
    }

    public function test_helper_throws_validation_exception_on_failure(): void
    {
        $this->expectException(ValidationException::class);

        validate(1, [Rule::string()]);
    }

    public function test_validate_many_helper(): void
    {
        $this->assertTrue(validateMany([
            'x' => [Rule::string()],
            '123' => [Rule::integer()],
        ]));
    }

    public function test_helper_uses_swapped_instance(): void
    {
        $spy = new class extends Validator {
            public bool $called = false;

            public function validate(mixed $value, array $rules): bool
            {
                $this->called = true;

                return true;
            }
        };

        Validator::swap($spy);

        validate('x', [Rule::string()]);

        $this->assertTrue($spy->called);
    }

    public function test_validation_error_renders_as_422_json_with_errors(): void
    {
        $app = new ValidatorController([
            'base_path' => __DIR__,
            'pages' => __DIR__ . '/fixtures/pages',
            'view_engine' => new SilentViewEngine(),
            'debug' => false,
        ]);

        // No `name` input -> required rule fails.
        $response = $app->handle(new Request(
            'GET',
            '/check',
            headers: ['accept' => 'application/json'],
        ));

        $this->assertSame(422, $response->getStatus());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);

        $payload = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('errors', $payload);
        $this->assertNotEmpty($payload['errors']);
    }
}
