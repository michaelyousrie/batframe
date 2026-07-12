<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Batframe;
use Batframe\Http\Request;
use Batframe\Routing\Route;
use Batframe\Routing\RouteResolver;
use PHPUnit\Framework\TestCase;

/**
 * A controller fixture exercising the naming convention.
 */
class ResolverController extends Batframe
{
    public function index() {}

    public function getUsers() {}

    public function getUserProfile() {}

    public function getUser(int $id) {}

    public function getUserPost(int $userId, int $postId) {}

    public function postUsers(Request $request) {}

    public function putUser(int $id, Request $request) {}

    public function deleteUser(int $id) {}

    public function getReport(string $slug) {}

    // No verb prefix: must NOT be routed.
    public function helperMethod() {}
}

final class RouteResolverTest extends TestCase
{
    /** @return array<string, Route> keyed "VERB path" */
    private function resolveIndexed(): array
    {
        $routes = (new RouteResolver())->resolve(new ResolverController(['base_path' => __DIR__]));

        $indexed = [];
        foreach ($routes as $route) {
            $indexed[$route->verb . ' ' . $route->path] = $route;
        }

        return $indexed;
    }

    public function test_it_maps_names_to_verbs_and_paths(): void
    {
        $routes = $this->resolveIndexed();

        $this->assertArrayHasKey('GET /', $routes);
        $this->assertArrayHasKey('GET /users', $routes);
        $this->assertArrayHasKey('GET /user/profile', $routes);
        $this->assertArrayHasKey('GET /user/{id}', $routes);
        $this->assertArrayHasKey('GET /user/post/{userId}/{postId}', $routes);
        $this->assertArrayHasKey('POST /users', $routes);
        $this->assertArrayHasKey('PUT /user/{id}', $routes);
        $this->assertArrayHasKey('DELETE /user/{id}', $routes);
        $this->assertArrayHasKey('GET /report/{slug}', $routes);
    }

    public function test_verbless_public_methods_are_not_routed(): void
    {
        $routes = $this->resolveIndexed();

        foreach ($routes as $route) {
            $this->assertNotSame('helperMethod', $route->handler);
        }
    }

    public function test_framework_methods_are_not_routed(): void
    {
        $routes = $this->resolveIndexed();

        foreach ($routes as $route) {
            $this->assertNotContains($route->handler, ['run', 'handle', 'boot', 'config']);
        }
    }

    public function test_int_param_constrains_the_segment(): void
    {
        $route = $this->resolveIndexed()['GET /user/{id}'];

        $this->assertSame(['id' => '42'], $route->match('/user/42'));
        $this->assertNull($route->match('/user/abc'));
    }

    public function test_string_param_matches_any_segment(): void
    {
        $route = $this->resolveIndexed()['GET /report/{slug}'];

        $this->assertSame(['slug' => 'q3-2026'], $route->match('/report/q3-2026'));
    }

    public function test_it_builds_arguments_with_request_injection_and_casting(): void
    {
        $route = $this->resolveIndexed()['PUT /user/{id}'];
        $request = new Request('PUT', '/user/7');

        $arguments = $route->buildArguments(['id' => '7'], $request);

        $this->assertSame(7, $arguments[0]);
        $this->assertSame($request, $arguments[1]);
    }
}
