<?php

declare(strict_types=1);

namespace Batframe\Validation;

use Batframe\Http\HttpException;

/**
 * Thrown when validation fails. It is an {@see HttpException} with a 422 status,
 * so Batframe's error pipeline renders it automatically (content-negotiated).
 * The individual failure messages are available via {@see errors()} and are
 * surfaced in the JSON body by `Batframe::renderException()`.
 *
 * For a single bare value the errors are a flat list of messages;
 * `request()->validate($key, ...)` keys them by the field name, and
 * `validateMany()` keys them by the entry that failed.
 */
final class ValidationException extends HttpException
{
    /**
     * @param array<int|string, mixed> $errors
     */
    public function __construct(
        private array $errors,
        string $message = '',
    ) {
        parent::__construct(422, $message === '' ? 'The given data was invalid.' : $message);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
