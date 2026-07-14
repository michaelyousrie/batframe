<?php

declare(strict_types=1);

namespace Batframe\Http;

/**
 * A lightweight, read-only representation of the incoming HTTP request.
 *
 * Build one from the current PHP superglobals with {@see Request::capture()},
 * or construct it explicitly (handy in tests).
 */
class Request
{
    /** The request currently being handled, for the `request()` helper. */
    private static ?self $current = null;

    /**
     * @param string                       $method  Uppercase HTTP method (GET, POST, ...).
     * @param string                       $path    Request path, always starting with "/".
     * @param array<string, mixed>         $query   Parsed query-string parameters ($_GET).
     * @param array<string, mixed>         $post    Parsed form body ($_POST).
     * @param array<string, string>        $headers Header name (lowercased) => value.
     * @param array<string, mixed>         $server  Raw server params ($_SERVER).
     * @param array<string, mixed>         $files   Uploaded files ($_FILES).
     * @param array<string, mixed>         $cookies Cookies ($_COOKIE).
     */
    public function __construct(
        private string $method,
        private string $path,
        private array $query = [],
        private array $post = [],
        private array $headers = [],
        private string $rawBody = '',
        private array $server = [],
        private array $files = [],
        private array $cookies = [],
    ) {
    }

    /**
     * Build a Request from PHP's superglobals and the raw input stream.
     */
    public static function capture(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);
        $path = is_string($path) ? rawurldecode($path) : '/';
        $path = self::normalizePath($path);

        $rawBody = (string) file_get_contents('php://input');

        return new self(
            method: $method,
            path: $path,
            query: $_GET,
            post: $_POST,
            headers: self::captureHeaders($_SERVER),
            rawBody: $rawBody,
            server: $_SERVER,
            files: $_FILES,
            cookies: $_COOKIE,
        );
    }

    /**
     * Collapse duplicate slashes and strip a trailing slash (except for root).
     */
    public static function normalizePath(string $path): string
    {
        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        $path = preg_replace('#/+#', '/', $path) ?? $path;

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        return $path === '' ? '/' : $path;
    }

    /**
     * @param array<string, mixed> $server
     * @return array<string, string>
     */
    private static function captureHeaders(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }

        // Content-Type / Content-Length are not prefixed with HTTP_ in $_SERVER.
        if (isset($server['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $server['CONTENT_TYPE'];
        }
        if (isset($server['CONTENT_LENGTH'])) {
            $headers['content-length'] = (string) $server['CONTENT_LENGTH'];
        }

        return $headers;
    }

    /**
     * The request currently being handled (null outside a request lifecycle).
     * Bound automatically by the app and read by the `request()` helper.
     */
    public static function current(): ?self
    {
        return self::$current;
    }

    /**
     * Bind (or clear, with null) the request the `request()` helper resolves to.
     * Called by the app on each request; also handy in tests.
     */
    public static function swap(?self $request): void
    {
        self::$current = $request;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isMethod(string $method): bool
    {
        return $this->method === strtoupper($method);
    }

    /**
     * A single header value (case-insensitive), or $default if absent.
     */
    public function header(string $name, ?string $default = null): ?string
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * A single query-string value by key, or every query parameter when called
     * with no key.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function query(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->query;
        }

        return $this->query[$key] ?? $default;
    }

    /**
     * A single GET value by key, or every GET parameter with no key. GET data is
     * the query string, so this reads the same source as {@see query()}; it reads
     * more naturally alongside {@see post()} when a route accepts both.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function get(?string $key = null, mixed $default = null): mixed
    {
        return $this->query($key, $default);
    }

    /**
     * The request body, regardless of how it was encoded: a single value by key,
     * or the whole body with no key. Reads the form body ($_POST) and the JSON
     * body together, with the JSON body winning on conflict (matching the
     * precedence of {@see input()} and {@see all()}). Use {@see form()} or
     * {@see json()} to target one encoding explicitly.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function post(?string $key = null, mixed $default = null): mixed
    {
        $body = array_merge($this->post, $this->json());

        if ($key === null) {
            return $body;
        }

        return $body[$key] ?? $default;
    }

    /**
     * A single form-body ($_POST) value by key, or the entire form body with no
     * key. Unlike {@see post()}, this never looks at the JSON body.
     *
     * @return ($key is null ? array<string, mixed> : mixed)
     */
    public function form(?string $key = null, mixed $default = null): mixed
    {
        if ($key === null) {
            return $this->post;
        }

        return $this->post[$key] ?? $default;
    }

    /**
     * Every GET (query-string) parameter.
     *
     * @return array<string, mixed>
     */
    public function allGet(): array
    {
        return $this->query;
    }

    /**
     * Every query-string parameter (alias of {@see allGet()}).
     *
     * @return array<string, mixed>
     */
    public function allQuery(): array
    {
        return $this->query;
    }

    /**
     * Every POST-body parameter, form and JSON merged (alias of {@see post()}
     * with no key). Use {@see form()} for the form body alone.
     *
     * @return array<string, mixed>
     */
    public function allPost(): array
    {
        return $this->post();
    }

    /**
     * Raw request body as a string.
     */
    public function body(): string
    {
        return $this->rawBody;
    }

    /**
     * The decoded JSON body as an associative array, or [] when the body is not
     * valid JSON. Pass a key to pull a single value from the decoded payload.
     */
    public function json(?string $key = null, mixed $default = null): mixed
    {
        $decoded = json_decode($this->rawBody, true);

        if (!is_array($decoded)) {
            $decoded = [];
        }

        if ($key === null) {
            return $decoded;
        }

        return $decoded[$key] ?? $default;
    }

    public function isJson(): bool
    {
        $contentType = $this->header('content-type', '') ?? '';

        return str_contains(strtolower($contentType), '/json')
            || str_contains(strtolower($contentType), '+json');
    }

    /**
     * True when the client would prefer a JSON response (Accept header asks for
     * it, the body is JSON, or the path lives under /api).
     */
    public function wantsJson(): bool
    {
        $accept = strtolower($this->header('accept', '') ?? '');

        return $this->isJson()
            || str_contains($accept, '/json')
            || str_contains($accept, '+json')
            || str_starts_with($this->path, '/api');
    }

    /**
     * Fetch an input value, looking across the JSON body, form body and query
     * string (in that order of precedence).
     */
    public function input(string $key, mixed $default = null): mixed
    {
        $sources = [$this->json(), $this->post, $this->query];

        foreach ($sources as $source) {
            if (is_array($source) && array_key_exists($key, $source)) {
                return $source[$key];
            }
        }

        return $default;
    }

    public function has(string $key): bool
    {
        return $this->input($key, $this) !== $this;
    }

    /**
     * Every input merged: query, then form body, then JSON body (later wins).
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_merge($this->query, $this->post, $this->json());
    }

    /**
     * Only the given input keys (skipping any that are absent).
     *
     * @return array<string, mixed>
     */
    public function only(string ...$keys): array
    {
        $all = $this->all();

        return array_intersect_key($all, array_flip($keys));
    }

    /**
     * Every input except the given keys.
     *
     * @return array<string, mixed>
     */
    public function except(string ...$keys): array
    {
        return array_diff_key($this->all(), array_flip($keys));
    }

    /**
     * True when the input is present and not "empty" (not null and not the empty
     * string, but 0 and "0" count as filled).
     */
    public function filled(string $key): bool
    {
        $value = $this->input($key);

        if (is_string($value)) {
            return $value !== '';
        }

        return $value !== null;
    }

    /**
     * Read an input as a boolean. Truthy values are 1, "1", true, "true", "on",
     * and "yes"; everything else (including absence) is false.
     */
    public function boolean(string $key, bool $default = false): bool
    {
        $value = $this->input($key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Read an input cast to an integer, with a fallback when absent.
     */
    public function integer(string $key, int $default = 0): int
    {
        $value = $this->input($key);

        return $value === null ? $default : (int) $value;
    }

    /**
     * Read an input cast to a string, with a fallback when absent.
     */
    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key);

        return $value === null ? $default : (string) $value;
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    public function cookie(string $key, mixed $default = null): mixed
    {
        return $this->cookies[$key] ?? $default;
    }

    /**
     * The Bearer token from the Authorization header, or null.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('authorization', '') ?? '';

        if (preg_match('/^Bearer\s+(.+)$/i', $header, $matches) === 1) {
            return trim($matches[1]);
        }

        return null;
    }

    public function ip(): ?string
    {
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($this->server[$key])) {
                $value = (string) $this->server[$key];
                // X-Forwarded-For may be a comma-separated list; take the first.
                return trim(explode(',', $value)[0]);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function server(): array
    {
        return $this->server;
    }
}
