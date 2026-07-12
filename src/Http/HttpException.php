<?php

declare(strict_types=1);

namespace Batframe\Http;

use RuntimeException;
use Throwable;

/**
 * An exception that carries an HTTP status code. Throw it from a handler to
 * abort the request with a specific status, e.g. `throw new HttpException(403)`.
 */
class HttpException extends RuntimeException
{
    /** @var array<string, string> */
    private array $headers;

    /**
     * @param array<string, string> $headers
     */
    public function __construct(
        private int $statusCode = 500,
        string $message = '',
        array $headers = [],
        ?Throwable $previous = null,
    ) {
        if ($message === '') {
            $message = Response::phrase($statusCode);
        }

        parent::__construct($message, 0, $previous);

        $this->headers = $headers;
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }

    /**
     * @return array<string, string>
     */
    public function getHeaders(): array
    {
        return $this->headers;
    }
}
