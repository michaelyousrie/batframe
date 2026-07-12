<?php

declare(strict_types=1);

namespace Batframe\Support;

use Dotenv\Dotenv;

/**
 * Loads a .env file (if present) into the environment and provides typed access
 * to environment variables.
 */
final class Environment
{
    private static bool $loaded = false;

    /**
     * Load the .env file from the given directory. Safe to call more than once;
     * it only loads on the first call. Missing files are ignored so the
     * framework still runs with real environment variables only.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        if (class_exists(Dotenv::class) && is_dir($path)) {
            Dotenv::createImmutable($path)->safeLoad();
        }

        self::$loaded = true;
    }

    /**
     * Reset the loaded flag. Intended for tests.
     */
    public static function reset(): void
    {
        self::$loaded = false;
    }

    /**
     * Read an environment variable, coercing common literals
     * (true/false/null/empty) and stripping surrounding quotes.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        if (!is_string($value)) {
            return $value;
        }

        return match (strtolower($value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => self::stripQuotes($value),
        };
    }

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];

            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                return substr($value, 1, -1);
            }
        }

        return $value;
    }
}
