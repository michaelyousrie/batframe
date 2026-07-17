<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Batframe;
use Batframe\Http\Request;
use Batframe\View\ViewEngine;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * A view engine whose template set and rendering are configurable, so the
 * error-page resolution order can be pinned without touching the filesystem.
 */
final class ConfigurableViewEngine implements ViewEngine
{
    /**
     * @param list<string> $present Template names that exist.
     * @param list<string> $broken  Template names whose render() throws.
     */
    public function __construct(
        private array $present = [],
        private array $broken = [],
    ) {
    }

    public function render(string $template, array $data = []): string
    {
        if (in_array($template, $this->broken, true)) {
            throw new RuntimeException("template {$template} blew up");
        }

        $status = $data['status'] ?? '';
        $message = $data['message'] ?? '';

        return "[VIEW {$template}] status={$status} message={$message}";
    }

    public function exists(string $template): bool
    {
        return in_array($template, $this->present, true);
    }
}

class ErrorPageController extends Batframe
{
    public function getBoom(): never
    {
        throw new RuntimeException('Something exploded internally.');
    }
}

final class ErrorPageTest extends TestCase
{
    /**
     * @param list<string> $present
     * @param list<string> $broken
     */
    private function app(array $present = [], array $broken = [], bool $debug = false): ErrorPageController
    {
        return new ErrorPageController([
            'base_path' => __DIR__,
            'pages' => __DIR__ . '/fixtures/pages',
            'view_engine' => new ConfigurableViewEngine($present, $broken),
            'debug' => $debug,
        ]);
    }

    public function test_status_specific_error_view_is_used(): void
    {
        $response = $this->app(present: ['errors/404'])->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->getStatus());
        $this->assertStringContainsString('[VIEW errors/404]', $response->getBody());
    }

    public function test_catch_all_error_view_is_used_when_no_specific_one(): void
    {
        $response = $this->app(present: ['errors/error'])->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->getStatus());
        $this->assertStringContainsString('[VIEW errors/error]', $response->getBody());
    }

    public function test_status_specific_view_wins_over_catch_all(): void
    {
        $response = $this->app(present: ['errors/404', 'errors/error'])->handle(new Request('GET', '/nope'));

        $this->assertStringContainsString('[VIEW errors/404]', $response->getBody());
    }

    public function test_error_view_receives_status_and_message(): void
    {
        $response = $this->app(present: ['errors/error'])->handle(new Request('GET', '/nope'));

        $this->assertStringContainsString('status=404', $response->getBody());
        $this->assertStringContainsString('message=Not Found.', $response->getBody());
    }

    public function test_falls_back_to_builtin_page_when_no_error_view(): void
    {
        $response = $this->app()->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->getStatus());
        $this->assertStringNotContainsString('[VIEW', $response->getBody());
        $this->assertStringContainsString('404', $response->getBody());
    }

    public function test_broken_error_view_falls_back_to_builtin_page(): void
    {
        $response = $this->app(present: ['errors/404'], broken: ['errors/404'])
            ->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->getStatus());
        $this->assertStringNotContainsString('[VIEW', $response->getBody());
        $this->assertStringContainsString('404', $response->getBody());
    }

    public function test_debug_500_shows_builtin_trace_even_with_error_view(): void
    {
        $response = $this->app(present: ['errors/500', 'errors/error'], debug: true)
            ->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringNotContainsString('[VIEW', $response->getBody());
        $this->assertStringContainsString('RuntimeException', $response->getBody());
    }

    public function test_non_debug_500_uses_error_view(): void
    {
        $response = $this->app(present: ['errors/500'], debug: false)
            ->handle(new Request('GET', '/boom'));

        $this->assertSame(500, $response->getStatus());
        $this->assertStringContainsString('[VIEW errors/500]', $response->getBody());
        // Production must not leak the raw exception message.
        $this->assertStringContainsString('message=Internal Server Error', $response->getBody());
        $this->assertStringNotContainsString('exploded internally', $response->getBody());
    }

    public function test_non_500_uses_error_view_even_in_debug(): void
    {
        $response = $this->app(present: ['errors/404'], debug: true)->handle(new Request('GET', '/nope'));

        $this->assertStringContainsString('[VIEW errors/404]', $response->getBody());
    }

    public function test_json_errors_ignore_error_views(): void
    {
        $response = $this->app(present: ['errors/404', 'errors/error'])
            ->handle(new Request('GET', '/api/missing'));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $this->assertStringNotContainsString('[VIEW', $response->getBody());
    }
}
