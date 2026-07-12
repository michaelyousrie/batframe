<?php

declare(strict_types=1);

use Batframe\Batframe;
use Batframe\Helpers\Session;
use Batframe\Http\Response;
use Batframe\Support\Environment;

if (!function_exists('env')) {
    /**
     * Read an environment variable with literal coercion (true/false/null).
     */
    function env(string $key, mixed $default = null): mixed
    {
        return Environment::get($key, $default);
    }
}

if (!function_exists('config')) {
    /**
     * Read a config value by dot notation from the running app's config.
     */
    function config(string $key, mixed $default = null): mixed
    {
        $app = Batframe::current();

        if ($app === null) {
            return $default;
        }

        return $app->config()->get($key, $default);
    }
}

if (!function_exists('view')) {
    /**
     * Build a view response: `return view('home', ['name' => 'World']);`
     *
     * @param array<string, mixed> $data
     */
    function view(string $template, array $data = [], int $status = 200): Response
    {
        return Response::view($template, $data, $status);
    }
}

if (!function_exists('response')) {
    /**
     * Build a response. Arrays become JSON, strings become HTML, and no
     * argument yields an empty response you can build up fluently.
     */
    function response(mixed $content = '', int $status = 200, array $headers = []): Response
    {
        if (is_array($content)) {
            return Response::json($content, $status)->withHeaders($headers);
        }

        return Response::html((string) $content, $status)->withHeaders($headers);
    }
}

if (!function_exists('json')) {
    /**
     * Build a JSON response: `return json(['ok' => true], 201);`
     */
    function json(mixed $data, int $status = 200): Response
    {
        return Response::json($data, $status);
    }
}

if (!function_exists('redirect')) {
    /**
     * Build a redirect response.
     */
    function redirect(string $url, int $status = 302): Response
    {
        return Response::redirect($url, $status);
    }
}

if (!function_exists('session')) {
    /**
     * Access the session.
     *
     * - `session()` returns the {@see Session} instance for chaining
     *   (`session()->flash('status', 'Saved!')`).
     * - `session('key')` / `session('key', $default)` reads a value.
     * - `session(['a' => 1, 'b' => 2])` writes several values and returns the
     *   instance.
     *
     * @param string|array<string, mixed>|null $key
     */
    function session(string|array|null $key = null, mixed $default = null): mixed
    {
        $session = Session::instance();

        if ($key === null) {
            return $session;
        }

        if (is_array($key)) {
            $session->put($key);

            return $session;
        }

        return $session->get($key, $default);
    }
}

if (!function_exists('abort')) {
    /**
     * Abort the request with an HTTP status by throwing an HttpException.
     */
    function abort(int $status, string $message = ''): never
    {
        throw new Batframe\Http\HttpException($status, $message);
    }
}
