<?php

declare(strict_types=1);

namespace Batframe\Helpers;

use Batframe\Batframe;

/**
 * A small, intuitive key/value cache. File-based by default (like sessions),
 * with a per-item time-to-live so each entry can be as long or short lived as
 * you need. Reach it through the `cache()` helper:
 *
 *   cache()->put('report', $data, 3600);   // expires in an hour
 *   cache()->forever('config', $config);   // never expires
 *   $data = cache('report');               // read, or null when missing/stale
 *
 *   // compute-on-miss and store for 10 minutes
 *   $users = cache()->remember('users', 600, fn () => User::all());
 *
 * The default (shared) instance stores each entry as a file under the app's
 * cache directory. Pass no directory to `new Cache()` for an in-memory store
 * that lives only for the current request, which is also handy in tests.
 */
final class Cache
{
    /** Extension used for the files this cache manages. */
    private const EXTENSION = '.cache';

    private static ?self $instance = null;

    /** In-memory entries, used when no directory is configured. */
    private array $store = [];

    /**
     * @param string|null $directory Where to persist entries. When null, the
     *                               cache is purely in-memory (per request).
     * @param (callable():int)|null $clock Override for the current unix time,
     *                                     intended for deterministic tests.
     */
    public function __construct(
        private ?string $directory = null,
        private $clock = null,
    ) {
        if ($directory !== null) {
            $this->directory = rtrim($directory, '/\\');
        }
    }

    /**
     * The shared instance used by the `cache()` helper. Persists to the running
     * app's cache directory, falling back to the system temp dir when there is
     * no app booted yet (e.g. very early in a script).
     */
    public static function instance(): self
    {
        return self::$instance ??= new self(self::defaultDirectory());
    }

    /**
     * Replace (or clear, with null) the shared instance. Intended for tests.
     */
    public static function swap(?self $cache): void
    {
        self::$instance = $cache;
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * Read a value, returning $default when the key is missing or expired.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $entry = $this->read($key);

        if ($entry === null) {
            return $default;
        }

        return $entry['value'];
    }

    /**
     * True when the key is present and not expired.
     */
    public function has(string $key): bool
    {
        return $this->read($key) !== null;
    }

    /**
     * The inverse of {@see has()}.
     */
    public function missing(string $key): bool
    {
        return !$this->has($key);
    }

    /**
     * Read a value and remove it in one step.
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);

        return $value;
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Store a value. A positive $ttl (seconds) makes the entry short-lived;
     * null keeps it until it is explicitly forgotten. A zero or negative $ttl
     * is treated as already expired, so the key is removed instead.
     */
    public function put(string $key, mixed $value, ?int $ttl = null): void
    {
        if ($ttl !== null && $ttl <= 0) {
            $this->forget($key);

            return;
        }

        $expires = $ttl === null ? null : $this->now() + $ttl;

        $this->write($key, ['expires' => $expires, 'value' => $value]);
    }

    /**
     * Alias of {@see put()}.
     */
    public function set(string $key, mixed $value, ?int $ttl = null): void
    {
        $this->put($key, $value, $ttl);
    }

    /**
     * Store a value that never expires.
     */
    public function forever(string $key, mixed $value): void
    {
        $this->put($key, $value, null);
    }

    /**
     * Store a value only if the key isn't already present (and unexpired).
     * Returns true when it was written.
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($this->has($key)) {
            return false;
        }

        $this->put($key, $value, $ttl);

        return true;
    }

    // ------------------------------------------------------------------
    // Compute-on-miss
    // ------------------------------------------------------------------

    /**
     * Return the cached value, or compute it with $callback, store it for $ttl
     * seconds (null = forever), and return it.
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $entry = $this->read($key);

        if ($entry !== null) {
            return $entry['value'];
        }

        $value = $callback();
        $this->put($key, $value, $ttl);

        return $value;
    }

    /**
     * {@see remember()} with no expiry.
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    // ------------------------------------------------------------------
    // Counters
    // ------------------------------------------------------------------

    /**
     * Increase a stored integer (creating it at 0 first), preserving its ttl.
     */
    public function increment(string $key, int $amount = 1): int
    {
        $entry = $this->read($key);
        $value = (int) ($entry['value'] ?? 0) + $amount;

        // Keep whatever life the entry had left.
        $expires = $entry['expires'] ?? null;
        $this->write($key, ['expires' => $expires, 'value' => $value]);

        return $value;
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    // ------------------------------------------------------------------
    // Removing
    // ------------------------------------------------------------------

    /**
     * Remove one or more keys.
     *
     * @param string|list<string> $keys
     */
    public function forget(string|array $keys): void
    {
        foreach ((array) $keys as $key) {
            if ($this->directory === null) {
                unset($this->store[$key]);

                continue;
            }

            $path = $this->pathFor($key);

            if (is_file($path)) {
                @unlink($path);
            }
        }
    }

    /**
     * Remove every entry this cache manages.
     */
    public function flush(): void
    {
        if ($this->directory === null) {
            $this->store = [];

            return;
        }

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [] as $path) {
            @unlink($path);
        }
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    /**
     * Fetch a live entry, or null when missing/expired (deleting it on expiry).
     *
     * @return array{expires: int|null, value: mixed}|null
     */
    private function read(string $key): ?array
    {
        $entry = $this->directory === null
            ? ($this->store[$key] ?? null)
            : $this->readFile($key);

        if ($entry === null) {
            return null;
        }

        if ($entry['expires'] !== null && $entry['expires'] <= $this->now()) {
            $this->forget($key);

            return null;
        }

        return $entry;
    }

    /**
     * @param array{expires: int|null, value: mixed} $entry
     */
    private function write(string $key, array $entry): void
    {
        if ($this->directory === null) {
            $this->store[$key] = $entry;

            return;
        }

        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0777, true);
        }

        file_put_contents($this->pathFor($key), serialize($entry), LOCK_EX);
    }

    /**
     * @return array{expires: int|null, value: mixed}|null
     */
    private function readFile(string $key): ?array
    {
        $path = $this->pathFor($key);

        if (!is_file($path)) {
            return null;
        }

        $raw = @file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $entry = @unserialize($raw);

        // Corrupt or unexpected payload: treat as a miss and clean it up.
        if (!is_array($entry) || !array_key_exists('value', $entry) || !array_key_exists('expires', $entry)) {
            @unlink($path);

            return null;
        }

        return $entry;
    }

    private function pathFor(string $key): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . hash('sha256', $key) . self::EXTENSION;
    }

    private function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    private static function defaultDirectory(): string
    {
        $app = Batframe::current();

        $base = $app !== null
            ? $app->cachePath()
            : sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batframe';

        return $base . DIRECTORY_SEPARATOR . 'data';
    }
}
