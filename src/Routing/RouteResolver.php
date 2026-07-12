<?php

declare(strict_types=1);

namespace Batframe\Routing;

use Batframe\Batframe;
use Batframe\Http\Request;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;

/**
 * Turns a controller class into a table of {@see Route}s by convention:
 * a public method whose name begins with an HTTP verb becomes an endpoint whose
 * path is derived from the remaining words in the name, and whose typed scalar
 * parameters become path placeholders.
 */
final class RouteResolver
{
    private const VERBS = ['get', 'post', 'put', 'patch', 'delete', 'head', 'options'];

    /**
     * @return list<Route>
     */
    public function resolve(object|string $controller): array
    {
        $reflection = new ReflectionClass($controller);
        $routes = [];

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $route = $this->resolveMethod($method);

            if ($route !== null) {
                $routes[] = $route;
            }
        }

        return $routes;
    }

    private function resolveMethod(ReflectionMethod $method): ?Route
    {
        if ($this->shouldSkip($method)) {
            return null;
        }

        $name = $method->getName();

        [$verb, $remainder] = $this->splitVerb($name);

        if ($verb === null) {
            // Public method that is not a recognised endpoint: treat as a helper.
            return null;
        }

        $segments = $this->segmentsFromName($remainder);

        return $this->compile($verb, $segments, $method);
    }

    private function shouldSkip(ReflectionMethod $method): bool
    {
        if ($method->isStatic() || $method->isAbstract() || $method->isConstructor()) {
            return true;
        }

        if (str_starts_with($method->getName(), '__')) {
            return true;
        }

        // Methods declared on the framework's own base class (run(), etc.) or
        // above it are never treated as endpoints.
        $declaring = $method->getDeclaringClass()->getName();

        return $declaring === Batframe::class || is_subclass_of(Batframe::class, $declaring);
    }

    /**
     * @return array{0: string|null, 1: string} [verb, remainder-of-name]
     */
    private function splitVerb(string $name): array
    {
        if ($name === 'index') {
            return ['GET', 'index'];
        }

        foreach (self::VERBS as $verb) {
            if ($name === $verb) {
                return [strtoupper($verb), ''];
            }

            if (str_starts_with($name, $verb)) {
                $next = $name[strlen($verb)] ?? '';
                if ($next !== '' && ctype_upper($next)) {
                    return [strtoupper($verb), substr($name, strlen($verb))];
                }
            }
        }

        return [null, $name];
    }

    /**
     * Split a PascalCase remainder into lowercased path segments.
     * "UserProfile" => ["user", "profile"]. "index" (any case) => [] (root).
     *
     * @return list<string>
     */
    private function segmentsFromName(string $remainder): array
    {
        if ($remainder === '') {
            return [];
        }

        preg_match_all('/[A-Z]+(?=[A-Z][a-z])|[A-Z]?[a-z0-9]+|[A-Z]+/', $remainder, $matches);

        $words = array_map('strtolower', $matches[0]);

        if ($words === ['index']) {
            return [];
        }

        return $words;
    }

    /**
     * @param list<string> $nameSegments
     */
    private function compile(string $verb, array $nameSegments, ReflectionMethod $method): Route
    {
        $parameters = [];
        $paramSegments = [];

        foreach ($method->getParameters() as $parameter) {
            $binding = $this->bindParameter($parameter);
            $parameters[] = $binding;

            if ($binding['source'] === 'path') {
                $paramSegments[] = $binding;
            }
        }

        // Static (name-derived) segments first, then one segment per path param.
        $pathParts = [];
        $regexParts = [];

        foreach ($nameSegments as $segment) {
            $pathParts[] = $segment;
            $regexParts[] = preg_quote($segment, '#');
        }

        foreach ($paramSegments as $binding) {
            $pathParts[] = '{' . $binding['name'] . '}';
            $regexParts[] = '(?P<' . $binding['name'] . '>' . $this->constraint($binding['cast']) . ')';
        }

        $path = '/' . implode('/', $pathParts);
        $regex = $regexParts === []
            ? '#^/$#'
            : '#^/' . implode('/', $regexParts) . '$#';

        return new Route(
            verb: $verb,
            path: $path === '/' ? '/' : $path,
            regex: $regex,
            handler: $method->getName(),
            parameters: $parameters,
        );
    }

    /**
     * @return array{name: string, source: string, cast: string}
     */
    private function bindParameter(ReflectionParameter $parameter): array
    {
        $type = $parameter->getType();

        if ($type instanceof ReflectionNamedType && !$type->isBuiltin()) {
            $className = $type->getName();

            if ($className === Request::class || is_a($className, Request::class, true)) {
                return ['name' => $parameter->getName(), 'source' => 'request', 'cast' => 'object'];
            }

            // Unknown class dependency: no container in v1, so pass null.
            return ['name' => $parameter->getName(), 'source' => 'null', 'cast' => 'null'];
        }

        $cast = 'string';
        if ($type instanceof ReflectionNamedType) {
            $cast = match ($type->getName()) {
                'int' => 'int',
                'float' => 'float',
                'bool' => 'bool',
                default => 'string',
            };
        }

        return ['name' => $parameter->getName(), 'source' => 'path', 'cast' => $cast];
    }

    private function constraint(string $cast): string
    {
        return match ($cast) {
            'int' => '\d+',
            'float' => '\d+(?:\.\d+)?',
            default => '[^/]+',
        };
    }
}
