<?php

declare(strict_types=1);

namespace Batframe\Routing;

/**
 * The result of a successful route match: the route and the raw path values
 * captured from the request path.
 */
final class MatchedRoute
{
    /**
     * @param array<string, string> $parameters
     */
    public function __construct(
        public readonly Route $route,
        public readonly array $parameters,
    ) {
    }
}
