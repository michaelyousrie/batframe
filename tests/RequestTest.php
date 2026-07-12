<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    public function test_input_reads_across_json_form_and_query(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/users',
            query: ['q' => 'search'],
            post: ['form' => 'value'],
            headers: ['content-type' => 'application/json'],
            rawBody: json_encode(['name' => 'grace']) ?: '',
        );

        $this->assertSame('grace', $request->input('name'));
        $this->assertSame('value', $request->input('form'));
        $this->assertSame('search', $request->input('q'));
        $this->assertSame('fallback', $request->input('missing', 'fallback'));
    }

    public function test_json_body_parsing(): void
    {
        $request = new Request(
            method: 'POST',
            path: '/api/x',
            rawBody: json_encode(['a' => 1, 'b' => 2]) ?: '',
        );

        $this->assertSame(['a' => 1, 'b' => 2], $request->json());
        $this->assertSame(2, $request->json('b'));
        $this->assertTrue($request->wantsJson());
    }

    public function test_headers_are_case_insensitive(): void
    {
        $request = new Request('GET', '/', headers: ['x-custom' => 'yes']);

        $this->assertSame('yes', $request->header('X-Custom'));
        $this->assertSame('yes', $request->header('x-custom'));
        $this->assertNull($request->header('absent'));
    }

    public function test_bearer_token_extraction(): void
    {
        $request = new Request('GET', '/', headers: ['authorization' => 'Bearer abc.123']);

        $this->assertSame('abc.123', $request->bearerToken());
    }

    public function test_capture_headers_include_content_type(): void
    {
        $request = new Request('GET', '/', headers: ['content-type' => 'application/json']);

        $this->assertTrue($request->isJson());
    }

    public function test_normalize_path(): void
    {
        $this->assertSame('/', Request::normalizePath('/'));
        $this->assertSame('/users', Request::normalizePath('/users/'));
        $this->assertSame('/a/b', Request::normalizePath('//a///b/'));
        $this->assertSame('/x', Request::normalizePath('x'));
    }
}
