<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Http\Request;
use PHPUnit\Framework\TestCase;

final class RequestHelperTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::swap(null);
    }

    public function test_helper_no_arg_returns_bound_request(): void
    {
        $request = new Request('GET', '/');
        Request::swap($request);

        $this->assertSame($request, request());
    }

    public function test_helper_with_key_reads_across_query_and_body(): void
    {
        Request::swap(new Request(
            'POST',
            '/',
            query: ['q' => 'search'],
            post: ['name' => 'grace'],
        ));

        $this->assertSame('search', request('q'));
        $this->assertSame('grace', request('name'));
        $this->assertSame('fallback', request('missing', 'fallback'));
    }

    public function test_helper_returns_null_when_no_request_bound(): void
    {
        $this->assertNull(request());
        $this->assertSame('fallback', request('anything', 'fallback'));
    }

    public function test_bound_request_is_accessible_via_current(): void
    {
        $request = new Request('GET', '/');
        Request::swap($request);

        $this->assertSame($request, Request::current());
    }
}
