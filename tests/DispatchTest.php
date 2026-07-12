<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Batframe;
use Batframe\Http\Request;
use Batframe\View\ViewEngine;
use PHPUnit\Framework\TestCase;

/**
 * A no-op view engine so dispatch tests never touch the filesystem.
 */
final class NullViewEngine implements ViewEngine
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

class DispatchController extends Batframe
{
    public function index(): array
    {
        return ['home' => true];
    }

    public function getUsers(): array
    {
        return ['users' => ['ada', 'linus']];
    }

    public function getUser(int $id): array
    {
        return ['id' => $id];
    }

    public function postUsers(Request $request): \Batframe\Http\Response
    {
        return \Batframe\Http\Response::json(['created' => $request->input('name')], 201);
    }

    public function getStatus(): string
    {
        return 'OK';
    }
}

final class DispatchTest extends TestCase
{
    private function app(): DispatchController
    {
        return new DispatchController([
            'base_path' => __DIR__,
            'pages' => __DIR__ . '/fixtures/pages',
            'view_engine' => new NullViewEngine(),
            'debug' => false,
        ]);
    }

    public function test_array_return_is_json(): void
    {
        $response = $this->app()->handle(new Request('GET', '/users'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $this->assertSame('{"users":["ada","linus"]}', $response->getBody());
    }

    public function test_index_maps_to_root(): void
    {
        $response = $this->app()->handle(new Request('GET', '/'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('{"home":true}', $response->getBody());
    }

    public function test_path_param_is_cast(): void
    {
        $response = $this->app()->handle(new Request('GET', '/user/42'));

        $this->assertSame('{"id":42}', $response->getBody());
    }

    public function test_string_return_is_html(): void
    {
        $response = $this->app()->handle(new Request('GET', '/status'));

        $this->assertSame('text/html; charset=UTF-8', $response->getHeaders()['Content-Type']);
        $this->assertSame('OK', $response->getBody());
    }

    public function test_post_creates_with_201(): void
    {
        $request = new Request('POST', '/users', rawBody: json_encode(['name' => 'grace']) ?: '');
        $response = $this->app()->handle($request);

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('{"created":"grace"}', $response->getBody());
    }

    public function test_unmatched_int_param_is_404(): void
    {
        $response = $this->app()->handle(new Request('GET', '/user/abc'));

        $this->assertSame(404, $response->getStatus());
    }

    public function test_wrong_verb_is_405_with_allow_header(): void
    {
        $response = $this->app()->handle(new Request('DELETE', '/status'));

        $this->assertSame(405, $response->getStatus());
        $this->assertSame('GET', $response->getHeaders()['Allow']);
    }

    public function test_unknown_path_is_404(): void
    {
        $response = $this->app()->handle(new Request('GET', '/nope'));

        $this->assertSame(404, $response->getStatus());
    }

    public function test_static_html_page_fallback(): void
    {
        $response = $this->app()->handle(new Request('GET', '/about'));

        $this->assertSame(200, $response->getStatus());
        $this->assertStringContainsString('static html page', $response->getBody());
    }

    public function test_json_error_negotiation(): void
    {
        $response = $this->app()->handle(new Request('GET', '/api/missing'));

        $this->assertSame(404, $response->getStatus());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $this->assertStringContainsString('Not Found', $response->getBody());
    }
}
