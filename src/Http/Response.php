<?php

declare(strict_types=1);

namespace Batframe\Http;

use Batframe\View\ViewEngine;

/**
 * A fluent HTTP response. Build one with a static factory
 * (json/html/text/view/redirect/file/noContent), optionally tweak it with the
 * chainable status()/header() methods, then send() it.
 */
class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    private string $body = '';

    /** Pending view to render lazily at send time (template, data). */
    private ?string $viewTemplate = null;

    /** @var array<string, mixed> */
    private array $viewData = [];

    /**
     * The view engine used by Response::view(). Bound once by the app at boot.
     */
    private static ?ViewEngine $viewEngine = null;

    public function __construct(string $body = '', private int $status = 200, array $headers = [])
    {
        $this->body = $body;

        foreach ($headers as $name => $value) {
            $this->headers[$this->normalizeHeaderName((string) $name)] = (string) $value;
        }
    }

    public static function setViewEngine(?ViewEngine $engine): void
    {
        self::$viewEngine = $engine;
    }

    public static function getViewEngine(): ?ViewEngine
    {
        return self::$viewEngine;
    }

    // ------------------------------------------------------------------
    // Factories
    // ------------------------------------------------------------------

    /**
     * A JSON response. Arrays, JsonSerializable and scalars are all encoded.
     */
    public static function json(mixed $data, int $status = 200, int $flags = 0): self
    {
        $flags |= JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

        $response = new self((string) json_encode($data, $flags), $status);
        $response->headers['Content-Type'] = 'application/json';

        return $response;
    }

    public static function html(string $html, int $status = 200): self
    {
        $response = new self($html, $status);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';

        return $response;
    }

    public static function text(string $text, int $status = 200): self
    {
        $response = new self($text, $status);
        $response->headers['Content-Type'] = 'text/plain; charset=UTF-8';

        return $response;
    }

    /**
     * Render a template through the bound view engine.
     *
     * @param array<string, mixed> $data
     */
    public static function view(string $template, array $data = [], int $status = 200): self
    {
        $response = new self('', $status);
        $response->headers['Content-Type'] = 'text/html; charset=UTF-8';
        $response->viewTemplate = $template;
        $response->viewData = $data;

        return $response;
    }

    public static function redirect(string $url, int $status = 302): self
    {
        $response = new self('', $status);
        $response->headers['Location'] = $url;

        return $response;
    }

    /**
     * Send the contents of a file. The content type is guessed from the
     * extension when not overridden.
     */
    public static function file(string $path, ?string $contentType = null): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new HttpException(404, 'File not found.');
        }

        $response = new self((string) file_get_contents($path));
        $response->headers['Content-Type'] = $contentType ?? self::guessContentType($path);

        return $response;
    }

    public static function noContent(int $status = 204): self
    {
        return new self('', $status);
    }

    // ------------------------------------------------------------------
    // Fluent mutators
    // ------------------------------------------------------------------

    public function status(int $status): self
    {
        $this->status = $status;

        return $this;
    }

    public function header(string $name, string $value): self
    {
        $this->headers[$this->normalizeHeaderName($name)] = $value;

        return $this;
    }

    /**
     * @param array<string, string> $headers
     */
    public function withHeaders(array $headers): self
    {
        foreach ($headers as $name => $value) {
            $this->header((string) $name, (string) $value);
        }

        return $this;
    }

    public function body(string $body): self
    {
        $this->body = $body;
        $this->viewTemplate = null;

        return $this;
    }

    // ------------------------------------------------------------------
    // Accessors
    // ------------------------------------------------------------------

    public function getStatus(): int
    {
        return $this->status;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }

    /**
     * The final body string, rendering a pending view if necessary.
     */
    public function getBody(): string
    {
        if ($this->viewTemplate !== null) {
            if (self::$viewEngine === null) {
                throw new HttpException(500, 'No view engine configured to render "' . $this->viewTemplate . '".');
            }

            $this->body = self::$viewEngine->render($this->viewTemplate, $this->viewData);
            $this->viewTemplate = null;
        }

        return $this->body;
    }

    // ------------------------------------------------------------------
    // Emission
    // ------------------------------------------------------------------

    /**
     * Emit the status line, headers and body to the client.
     */
    public function send(): void
    {
        $body = $this->getBody();

        if (!headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value, true);
            }
        }

        echo $body;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function normalizeHeaderName(string $name): string
    {
        // Canonicalise to Title-Case (e.g. content-type -> Content-Type).
        return implode('-', array_map(
            static fn (string $part): string => ucfirst(strtolower($part)),
            explode('-', $name),
        ));
    }

    private static function guessContentType(string $path): string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'html', 'htm' => 'text/html; charset=UTF-8',
            'css' => 'text/css; charset=UTF-8',
            'js' => 'application/javascript; charset=UTF-8',
            'json' => 'application/json',
            'txt' => 'text/plain; charset=UTF-8',
            'xml' => 'application/xml',
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }

    /**
     * The standard reason phrase for an HTTP status code.
     */
    public static function phrase(int $status): string
    {
        return match ($status) {
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            204 => 'No Content',
            301 => 'Moved Permanently',
            302 => 'Found',
            304 => 'Not Modified',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            409 => 'Conflict',
            422 => 'Unprocessable Entity',
            429 => 'Too Many Requests',
            500 => 'Internal Server Error',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            default => 'Unknown Status',
        };
    }
}
