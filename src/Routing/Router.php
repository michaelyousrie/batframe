<?php

declare(strict_types=1);

namespace Batframe\Routing;

use Batframe\Http\Request;

/**
 * Holds the compiled route table and matches an incoming request against it.
 */
final class Router
{
    /** @var list<Route> */
    private array $routes = [];

    public function add(Route $route): void
    {
        $this->routes[] = $route;
    }

    /**
     * @param iterable<Route> $routes
     */
    public function addMany(iterable $routes): void
    {
        foreach ($routes as $route) {
            $this->add($route);
        }
    }

    /**
     * @return list<Route>
     */
    public function routes(): array
    {
        return $this->routes;
    }

    /**
     * Find the route matching this request. Returns the matched route together
     * with the captured path values, or null when nothing matches.
     */
    public function match(Request $request): ?MatchedRoute
    {
        foreach ($this->routes as $route) {
            if ($route->verb !== $request->method()) {
                continue;
            }

            $params = $route->match($request->path());

            if ($params !== null) {
                return new MatchedRoute($route, $params);
            }
        }

        return null;
    }

    /**
     * The HTTP verbs that would match this path under any method. Used to build
     * a 405 response's Allow header, and to tell a genuine 404 (empty) apart
     * from a method mismatch.
     *
     * @return list<string>
     */
    public function allowedMethods(string $path): array
    {
        $allowed = [];

        foreach ($this->routes as $route) {
            if ($route->match($path) !== null && !in_array($route->verb, $allowed, true)) {
                $allowed[] = $route->verb;
            }
        }

        return $allowed;
    }
}
