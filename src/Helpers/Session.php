<?php

declare(strict_types=1);

namespace Batframe\Helpers;

/**
 * A thin, intuitive wrapper around PHP's native (file-based) sessions.
 *
 * The session starts lazily the first time you touch it, so no session cookie is
 * sent unless you actually use it. Reach it through the `session()` helper:
 *
 *   session()->put('user_id', 42);
 *   $id = session('user_id');
 *   session()->flash('status', 'Saved!');
 *
 * In tests, construct an array-backed instance with `new Session(false)` (or
 * `Session::swap(new Session(false))`) to avoid starting a real OS session.
 */
final class Session
{
    /** The internal key under which flash bookkeeping is stored. */
    private const FLASH_KEY = '_flash';

    private bool $started = false;

    private static ?self $instance = null;

    /**
     * @param bool $native When true, use PHP's native session (files). When
     *                      false, operate purely on the $_SESSION array (tests).
     */
    public function __construct(private bool $native = true)
    {
    }

    /**
     * The shared instance used by the `session()` helper.
     */
    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    /**
     * Replace (or clear, with null) the shared instance. Intended for tests.
     */
    public static function swap(?self $session): void
    {
        self::$instance = $session;
    }

    // ------------------------------------------------------------------
    // Lifecycle
    // ------------------------------------------------------------------

    /**
     * Start the session if it isn't already. Idempotent, and safe to call when a
     * real session can't be started (CLI, headers already sent): it falls back
     * to array storage. Ages flash data once per request.
     */
    public function start(): bool
    {
        if ($this->started) {
            return true;
        }

        if ($this->native && session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if (!isset($_SESSION) || !is_array($_SESSION)) {
            $_SESSION = [];
        }

        $this->started = true;
        $this->ageFlashData();

        return true;
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    public function id(): ?string
    {
        $this->start();

        return $this->native && session_status() === PHP_SESSION_ACTIVE ? session_id() : null;
    }

    /**
     * Generate a fresh session id, keeping the data. Useful after login to
     * prevent session fixation.
     */
    public function regenerate(bool $deleteOld = true): bool
    {
        $this->start();

        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            return session_regenerate_id($deleteOld);
        }

        return false;
    }

    /**
     * Clear all data and regenerate the id.
     */
    public function invalidate(): bool
    {
        $this->flush();

        return $this->regenerate(true);
    }

    /**
     * Destroy the session entirely.
     */
    public function destroy(): void
    {
        if ($this->native && session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        } else {
            $_SESSION = [];
        }

        $this->started = false;
    }

    // ------------------------------------------------------------------
    // Reading / writing
    // ------------------------------------------------------------------

    public function get(string $key, mixed $default = null): mixed
    {
        $this->start();

        return $_SESSION[$key] ?? $default;
    }

    /**
     * Store one value, or many at once by passing an array.
     *
     * @param string|array<string, mixed> $key
     */
    public function put(string|array $key, mixed $value = null): void
    {
        $this->start();

        $pairs = is_array($key) ? $key : [$key => $value];

        foreach ($pairs as $k => $v) {
            $_SESSION[$k] = $v;
        }
    }

    /**
     * Alias of {@see put()} for a single value.
     */
    public function set(string $key, mixed $value): void
    {
        $this->put($key, $value);
    }

    /**
     * True when the key is present and not null.
     */
    public function has(string $key): bool
    {
        $this->start();

        return isset($_SESSION[$key]);
    }

    /**
     * True when the key is present, even if its value is null.
     */
    public function exists(string $key): bool
    {
        $this->start();

        return array_key_exists($key, $_SESSION);
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

    /**
     * Remove one or more keys.
     *
     * @param string|list<string> $keys
     */
    public function forget(string|array $keys): void
    {
        $this->start();

        foreach ((array) $keys as $key) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Append a value to an array stored under the key (creating it if needed).
     */
    public function push(string $key, mixed $value): void
    {
        $this->start();

        $current = $_SESSION[$key] ?? [];

        if (!is_array($current)) {
            $current = [$current];
        }

        $current[] = $value;
        $_SESSION[$key] = $current;
    }

    public function increment(string $key, int $amount = 1): int
    {
        $this->start();

        $value = (int) ($_SESSION[$key] ?? 0) + $amount;
        $_SESSION[$key] = $value;

        return $value;
    }

    public function decrement(string $key, int $amount = 1): int
    {
        return $this->increment($key, -$amount);
    }

    /**
     * All session data, excluding internal flash bookkeeping.
     *
     * @return array<string, mixed>
     */
    public function all(): array
    {
        $this->start();

        $data = $_SESSION;
        unset($data[self::FLASH_KEY]);

        return $data;
    }

    /**
     * Remove every value.
     */
    public function flush(): void
    {
        $this->start();

        $_SESSION = [];
    }

    // ------------------------------------------------------------------
    // Flash data (lives for the next request only)
    // ------------------------------------------------------------------

    /**
     * Store a value that will be available on the next request, then removed.
     */
    public function flash(string $key, mixed $value = true): void
    {
        $this->start();

        $_SESSION[$key] = $value;
        $_SESSION[self::FLASH_KEY]['new'][] = $key;
        $this->removeFromOldFlash($key);
    }

    /**
     * Keep all current flash data for one more request.
     */
    public function reflash(): void
    {
        $this->start();

        $old = $_SESSION[self::FLASH_KEY]['old'] ?? [];
        $_SESSION[self::FLASH_KEY]['new'] = array_values(array_unique(
            array_merge($_SESSION[self::FLASH_KEY]['new'] ?? [], $old)
        ));
        $_SESSION[self::FLASH_KEY]['old'] = [];
    }

    /**
     * Keep specific flash keys for one more request.
     *
     * @param string|list<string> $keys
     */
    public function keep(string|array $keys): void
    {
        $this->start();

        foreach ((array) $keys as $key) {
            $_SESSION[self::FLASH_KEY]['new'][] = $key;
            $this->removeFromOldFlash($key);
        }

        $_SESSION[self::FLASH_KEY]['new'] = array_values(array_unique($_SESSION[self::FLASH_KEY]['new']));
    }

    /**
     * Expire the previous request's flash data and roll this request's flash
     * into the "old" bucket so it survives exactly one more request.
     */
    private function ageFlashData(): void
    {
        foreach ($_SESSION[self::FLASH_KEY]['old'] ?? [] as $key) {
            unset($_SESSION[$key]);
        }

        $_SESSION[self::FLASH_KEY]['old'] = $_SESSION[self::FLASH_KEY]['new'] ?? [];
        $_SESSION[self::FLASH_KEY]['new'] = [];
    }

    private function removeFromOldFlash(string $key): void
    {
        if (!isset($_SESSION[self::FLASH_KEY]['old'])) {
            return;
        }

        $_SESSION[self::FLASH_KEY]['old'] = array_values(
            array_filter($_SESSION[self::FLASH_KEY]['old'], static fn ($k) => $k !== $key)
        );
    }
}
