<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Http\Request;
use Batframe\Validation\Rule;
use Batframe\Validation\ValidationException;
use PHPUnit\Framework\TestCase;

final class RequestTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::swap(null);
    }

    public function test_validate_reads_the_named_value_and_passes(): void
    {
        Request::swap(new Request(
            method: 'POST',
            path: '/users',
            post: ['name' => 'ada'],
        ));

        $this->assertTrue(request()->validate('name', [Rule::required(), Rule::string()]));
    }

    public function test_validate_validates_the_value_not_the_key(): void
    {
        // The key 'age' would fail integer(); its value '123' passes.
        Request::swap(new Request(
            method: 'POST',
            path: '/users',
            post: ['age' => '123'],
        ));

        $this->assertTrue(request()->validate('age', [Rule::integer()]));
    }

    public function test_validate_throws_when_the_named_value_fails(): void
    {
        Request::swap(new Request(method: 'POST', path: '/users', post: []));

        $this->expectException(ValidationException::class);

        // 'name' is absent -> required() fails on the resolved (null) value.
        request()->validate('name', [Rule::required()]);
    }

    public function test_validate_keys_its_errors_by_the_param_name(): void
    {
        Request::swap(new Request(method: 'GET', path: '/user', query: ['score' => 'tes']));

        try {
            request()->validate('score', [Rule::required(), Rule::numeric()]);
            $this->fail('Expected ValidationException');
        } catch (ValidationException $e) {
            $this->assertSame(
                ['score' => ['This value must be numeric.']],
                $e->errors(),
            );
        }
    }

    public function test_validate_resolves_value_like_the_request_helper(): void
    {
        // request() resolves across JSON body / form / query, so validate() does too.
        Request::swap(new Request(
            method: 'POST',
            path: '/api/users',
            query: ['q' => 'search'],
            headers: ['content-type' => 'application/json'],
            rawBody: json_encode(['email' => 'a@b.com']) ?: '',
        ));

        $this->assertTrue(request()->validate('email', [Rule::required(), Rule::email()]));
        $this->assertTrue(request()->validate('q', [Rule::required(), Rule::string()]));
    }

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

    public function test_get_and_query_read_query_params_by_key(): void
    {
        $request = new Request('GET', '/', query: ['q' => 'search', 'page' => '2']);

        $this->assertSame('search', $request->get('q'));
        $this->assertSame('search', $request->query('q'));
        $this->assertSame('fallback', $request->get('missing', 'fallback'));
        $this->assertSame('fallback', $request->query('missing', 'fallback'));
    }

    public function test_post_reads_form_body_by_key(): void
    {
        $request = new Request('POST', '/', post: ['name' => 'grace']);

        $this->assertSame('grace', $request->post('name'));
        $this->assertSame('fallback', $request->post('missing', 'fallback'));
    }

    public function test_post_reads_json_body_by_key(): void
    {
        $request = new Request(
            'POST',
            '/',
            headers: ['content-type' => 'application/json'],
            rawBody: json_encode(['name' => 'grace']) ?: '',
        );

        // post() must see the JSON body, not just the form body.
        $this->assertSame('grace', $request->post('name'));
        $this->assertSame('fallback', $request->post('missing', 'fallback'));
    }

    public function test_post_merges_form_and_json_body(): void
    {
        $request = new Request(
            'POST',
            '/',
            post: ['name' => 'form-grace', 'source' => 'form'],
            headers: ['content-type' => 'application/json'],
            rawBody: json_encode(['name' => 'json-grace', 'extra' => 'json']) ?: '',
        );

        // JSON body wins on conflict, matching input()/all() precedence.
        $this->assertSame('json-grace', $request->post('name'));
        $this->assertSame('form', $request->post('source'));
        $this->assertSame('json', $request->post('extra'));
        $this->assertSame(
            ['name' => 'json-grace', 'source' => 'form', 'extra' => 'json'],
            $request->post(),
        );
        $this->assertSame(
            ['name' => 'json-grace', 'source' => 'form', 'extra' => 'json'],
            $request->allPost(),
        );
    }

    public function test_form_reads_only_the_form_body(): void
    {
        $request = new Request(
            'POST',
            '/',
            post: ['name' => 'form-grace'],
            headers: ['content-type' => 'application/json'],
            rawBody: json_encode(['token' => 'json-only']) ?: '',
        );

        $this->assertSame('form-grace', $request->form('name'));
        // form() must NOT reach into the JSON body.
        $this->assertNull($request->form('token'));
        $this->assertSame(['name' => 'form-grace'], $request->form());
    }

    public function test_json_stays_explicit_to_the_json_body(): void
    {
        $request = new Request(
            'POST',
            '/',
            post: ['name' => 'form-grace'],
            headers: ['content-type' => 'application/json'],
            rawBody: json_encode(['token' => 'json-only']) ?: '',
        );

        // json() must NOT reach into the form body.
        $this->assertNull($request->json('name'));
        $this->assertSame('json-only', $request->json('token'));
    }

    public function test_source_accessors_return_full_arrays(): void
    {
        $request = new Request(
            'POST',
            '/',
            query: ['q' => 'search'],
            post: ['name' => 'grace'],
        );

        $this->assertSame(['q' => 'search'], $request->query());
        $this->assertSame(['q' => 'search'], $request->allGet());
        $this->assertSame(['q' => 'search'], $request->allQuery());
        $this->assertSame(['name' => 'grace'], $request->post());
        $this->assertSame(['name' => 'grace'], $request->allPost());
        $this->assertSame(['q' => 'search', 'name' => 'grace'], $request->all());
    }

    public function test_only_and_except_filter_inputs(): void
    {
        $request = new Request(
            'POST',
            '/',
            query: ['q' => 'search'],
            post: ['name' => 'grace', 'age' => '30'],
        );

        $this->assertSame(['q' => 'search', 'name' => 'grace'], $request->only('q', 'name'));
        $this->assertSame(['q' => 'search'], $request->except('name', 'age'));
    }

    public function test_filled_and_boolean_helpers(): void
    {
        $request = new Request(
            'POST',
            '/',
            query: ['empty' => '', 'active' => 'true', 'off' => '0'],
            post: ['name' => 'grace'],
        );

        $this->assertTrue($request->filled('name'));
        $this->assertFalse($request->filled('empty'));
        $this->assertFalse($request->filled('missing'));

        $this->assertTrue($request->boolean('active'));
        $this->assertFalse($request->boolean('off'));
        $this->assertFalse($request->boolean('missing'));
    }

    public function test_integer_and_string_casts(): void
    {
        $request = new Request('GET', '/', query: ['page' => '3', 'name' => 42]);

        $this->assertSame(3, $request->integer('page'));
        $this->assertSame(1, $request->integer('missing', 1));
        $this->assertSame('42', $request->string('name'));
        $this->assertSame('n/a', $request->string('missing', 'n/a'));
    }
}
