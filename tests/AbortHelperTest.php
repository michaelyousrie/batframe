<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Http\HttpException;
use PHPUnit\Framework\TestCase;

final class AbortHelperTest extends TestCase
{
    public function test_abort_throws_an_http_exception_with_the_status(): void
    {
        // Regression: helpers.php imports `Batframe\Batframe` as the alias
        // `Batframe`, so the relative name `Batframe\Http\HttpException` inside
        // abort() resolved through that alias to `Batframe\Batframe\Http\...`
        // and every abort() died as a 500 "class not found" instead of the
        // status it was asked for.
        try {
            abort(404, 'There is no user 99.');
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('There is no user 99.', $e->getMessage());
        }
    }

    public function test_abort_fills_in_the_standard_phrase(): void
    {
        try {
            abort(403);
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('Forbidden', $e->getMessage());
        }
    }
}
