<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Http\Response;
use Batframe\View\ViewEngine;
use PHPUnit\Framework\TestCase;

final class ResponseTest extends TestCase
{
    protected function tearDown(): void
    {
        Response::setViewEngine(null);
    }

    public function test_json_factory(): void
    {
        $response = Response::json(['ok' => true], 201);

        $this->assertSame(201, $response->getStatus());
        $this->assertSame('application/json', $response->getHeaders()['Content-Type']);
        $this->assertSame('{"ok":true}', $response->getBody());
    }

    public function test_html_and_text_factories(): void
    {
        $html = Response::html('<p>hi</p>');
        $this->assertSame('text/html; charset=UTF-8', $html->getHeaders()['Content-Type']);

        $text = Response::text('plain');
        $this->assertSame('text/plain; charset=UTF-8', $text->getHeaders()['Content-Type']);
    }

    public function test_redirect_sets_location(): void
    {
        $response = Response::redirect('/login', 301);

        $this->assertSame(301, $response->getStatus());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function test_fluent_status_and_headers(): void
    {
        $response = Response::html('x')
            ->status(418)
            ->header('x-teapot', 'yes')
            ->withHeaders(['x-extra' => '1']);

        $this->assertSame(418, $response->getStatus());
        $this->assertSame('yes', $response->getHeaders()['X-Teapot']);
        $this->assertSame('1', $response->getHeaders()['X-Extra']);
    }

    public function test_no_content(): void
    {
        $this->assertSame(204, Response::noContent()->getStatus());
    }

    public function test_view_renders_through_bound_engine(): void
    {
        Response::setViewEngine(new class implements ViewEngine {
            public function render(string $template, array $data = []): string
            {
                return "rendered:{$template}:" . ($data['name'] ?? '');
            }

            public function exists(string $template): bool
            {
                return true;
            }
        });

        $response = Response::view('home', ['name' => 'World']);

        $this->assertSame('rendered:home:World', $response->getBody());
    }
}
