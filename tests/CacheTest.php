<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Helpers\Cache;
use PHPUnit\Framework\TestCase;

final class CacheTest extends TestCase
{
    private string $dir;

    /** Mutable "now", so ttl expiry is deterministic without sleeping. */
    private int $now = 1_000_000;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batframe-cache-test-' . getmypid() . '-' . uniqid();

        Cache::swap($this->fileCache());
    }

    protected function tearDown(): void
    {
        Cache::swap(null);

        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    private function fileCache(): Cache
    {
        return new Cache($this->dir, fn (): int => $this->now);
    }

    public function test_put_and_get_via_helper(): void
    {
        cache()->put('name', 'ada');

        $this->assertSame('ada', cache('name'));
        $this->assertSame('fallback', cache('missing', 'fallback'));
    }

    public function test_helper_no_arg_returns_instance(): void
    {
        $this->assertInstanceOf(Cache::class, cache());
    }

    public function test_helper_array_writes_with_ttl(): void
    {
        cache(['a' => 1, 'b' => 2], 60);

        $this->assertSame(1, cache('a'));
        $this->assertSame(2, cache('b'));

        // Still alive just before expiry, gone just after.
        $this->now += 59;
        $this->assertSame(1, cache('a'));

        $this->now += 2;
        $this->assertNull(cache('a'));
    }

    public function test_ttl_expires_the_entry(): void
    {
        $cache = cache();
        $cache->put('short', 'x', 10);

        $this->assertTrue($cache->has('short'));

        $this->now += 11;

        $this->assertFalse($cache->has('short'));
        $this->assertNull($cache->get('short'));
    }

    public function test_forever_never_expires(): void
    {
        cache()->forever('long', 'y');

        $this->now += 10_000_000;

        $this->assertSame('y', cache('long'));
    }

    public function test_non_positive_ttl_is_treated_as_expired(): void
    {
        $cache = cache();
        $cache->put('gone', 'z', 0);
        $this->assertFalse($cache->has('gone'));

        $cache->put('present', 'here');
        $cache->put('present', 'replaced', -5);
        $this->assertFalse($cache->has('present'));
    }

    public function test_add_only_writes_when_absent(): void
    {
        $cache = cache();

        $this->assertTrue($cache->add('k', 'first'));
        $this->assertFalse($cache->add('k', 'second'));
        $this->assertSame('first', $cache->get('k'));
    }

    public function test_remember_computes_once_then_caches(): void
    {
        $cache = cache();
        $calls = 0;

        $make = function () use (&$calls) {
            $calls++;

            return 'computed';
        };

        $this->assertSame('computed', $cache->remember('r', 60, $make));
        $this->assertSame('computed', $cache->remember('r', 60, $make));
        $this->assertSame(1, $calls);

        // Once it expires, the callback runs again.
        $this->now += 61;
        $this->assertSame('computed', $cache->remember('r', 60, $make));
        $this->assertSame(2, $calls);
    }

    public function test_pull_reads_and_removes(): void
    {
        $cache = cache();
        $cache->put('once', 'value');

        $this->assertSame('value', $cache->pull('once'));
        $this->assertFalse($cache->has('once'));
    }

    public function test_increment_and_decrement(): void
    {
        $cache = cache();

        $this->assertSame(1, $cache->increment('hits'));
        $this->assertSame(3, $cache->increment('hits', 2));
        $this->assertSame(2, $cache->decrement('hits'));
    }

    public function test_increment_preserves_ttl(): void
    {
        $cache = cache();
        $cache->put('counter', 5, 60);

        $cache->increment('counter');
        $this->assertSame(6, $cache->get('counter'));

        // Still bound by the original ttl, not reset to forever.
        $this->now += 61;
        $this->assertNull($cache->get('counter'));
    }

    public function test_forget_and_flush(): void
    {
        $cache = cache();
        $cache->put('a', 1);
        $cache->put('b', 2);
        $cache->put('c', 3);

        $cache->forget(['a', 'b']);
        $this->assertFalse($cache->has('a'));
        $this->assertTrue($cache->has('c'));

        $cache->flush();
        $this->assertFalse($cache->has('c'));
    }

    public function test_stores_arbitrary_serializable_values(): void
    {
        $payload = ['nested' => ['x' => 1], 'list' => [1, 2, 3]];
        cache()->put('data', $payload);

        $this->assertSame($payload, cache('data'));
    }

    public function test_file_store_persists_across_instances(): void
    {
        // Write with one instance...
        $this->fileCache()->put('shared', 'kept', 60);

        // ...and read it back with a fresh instance over the same directory.
        $this->assertSame('kept', $this->fileCache()->get('shared'));
    }

    public function test_in_memory_store_needs_no_directory(): void
    {
        $cache = new Cache();
        $cache->put('k', 'v', 60);

        $this->assertSame('v', $cache->get('k'));
        $this->assertTrue($cache->has('k'));

        $cache->forget('k');
        $this->assertNull($cache->get('k'));
    }
}
