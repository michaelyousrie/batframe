<?php

declare(strict_types=1);

namespace Batframe\DataStorage;

use Batframe\Http\HttpException;
use Throwable;

/**
 * Something went wrong talking to the store: an unreadable data file, a
 * duplicate id, an unknown driver, an illegal collection name, an operator a
 * driver cannot express.
 *
 * It extends {@see HttpException} so the existing pipeline renders it like any
 * other failure. The status is 500: a store that cannot answer is a server
 * problem, and the rest of these are programming errors, not user input.
 */
final class DataStorageException extends HttpException
{
    public function __construct(string $message, ?Throwable $previous = null)
    {
        parent::__construct(500, $message, previous: $previous);
    }
}
