<?php

declare(strict_types=1);

namespace Batframe\Routing;

use Batframe\Http\Request;

/**
 * A single compiled route: an HTTP verb, a regex that matches the path and
 * captures its parameters, and the metadata needed to invoke the handler with
 * arguments bound in the handler's declared order.
 */
final class Route
{
    /**
     * @param string                       $verb       Uppercase HTTP method.
     * @param string                       $path        Human-readable path with {placeholders}.
     * @param string                       $regex       Compiled matcher with named capture groups.
     * @param string                       $handler     The controller method name.
     * @param list<array{name: string, source: string, cast: string}> $parameters
     *        Ordered binding descriptors, one per handler parameter. `source`
     *        is one of "path", "request" or "null".
     */
    public function __construct(
        public readonly string $verb,
        public readonly string $path,
        public readonly string $regex,
        public readonly string $handler,
        public readonly array $parameters,
    ) {
    }

    /**
     * Match a path against this route, returning the captured (raw) parameter
     * values keyed by name, or null when it does not match.
     *
     * @return array<string, string>|null
     */
    public function match(string $path): ?array
    {
        if (preg_match($this->regex, $path, $matches) !== 1) {
            return null;
        }

        $params = [];
        foreach ($matches as $key => $value) {
            if (is_string($key)) {
                $params[$key] = $value;
            }
        }

        return $params;
    }

    /**
     * Build the ordered argument list for the handler from captured path values
     * and the current request.
     *
     * @param array<string, string> $pathValues
     * @return list<mixed>
     */
    public function buildArguments(array $pathValues, Request $request): array
    {
        $arguments = [];

        foreach ($this->parameters as $parameter) {
            $arguments[] = match ($parameter['source']) {
                'request' => $request,
                'path' => self::cast($pathValues[$parameter['name']] ?? null, $parameter['cast']),
                default => null,
            };
        }

        return $arguments;
    }

    private static function cast(?string $value, string $type): mixed
    {
        if ($value === null) {
            return null;
        }

        return match ($type) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            default => $value,
        };
    }
}
