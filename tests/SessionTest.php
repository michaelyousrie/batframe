<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Helpers\Session;
use PHPUnit\Framework\TestCase;

final class SessionTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
        // Array-backed instance so tests never start a real OS session.
        Session::swap(new Session(false));
    }

    protected function tearDown(): void
    {
        Session::swap(null);
        $_SESSION = [];
    }

    public function test_put_and_get_via_helper(): void
    {
        session()->put('user_id', 42);

        $this->assertSame(42, session('user_id'));
        $this->assertSame('fallback', session('missing', 'fallback'));
    }

    public function test_helper_no_arg_returns_instance(): void
    {
        $this->assertInstanceOf(Session::class, session());
    }

    public function test_helper_array_sets_many(): void
    {
        session(['theme' => 'dark', 'lang' => 'en']);

        $this->assertSame('dark', session('theme'));
        $this->assertSame('en', session('lang'));
    }

    public function test_has_and_exists(): void
    {
        $session = session();
        $session->put('null_value', null);
        $session->put('real', 'x');

        $this->assertTrue($session->exists('null_value'));
        $this->assertFalse($session->has('null_value'));
        $this->assertTrue($session->has('real'));
        $this->assertFalse($session->exists('absent'));
    }

    public function test_pull_reads_and_removes(): void
    {
        $session = session();
        $session->put('cart', ['a', 'b']);

        $this->assertSame(['a', 'b'], $session->pull('cart'));
        $this->assertFalse($session->has('cart'));
    }

    public function test_forget_and_flush(): void
    {
        $session = session();
        $session->put(['a' => 1, 'b' => 2, 'c' => 3]);

        $session->forget(['a', 'b']);
        $this->assertFalse($session->has('a'));
        $this->assertTrue($session->has('c'));

        $session->flush();
        $this->assertSame([], $session->all());
    }

    public function test_push_appends_to_array(): void
    {
        $session = session();
        $session->push('items', 'first');
        $session->push('items', 'second');

        $this->assertSame(['first', 'second'], session('items'));
    }

    public function test_increment_and_decrement(): void
    {
        $session = session();

        $this->assertSame(1, $session->increment('visits'));
        $this->assertSame(3, $session->increment('visits', 2));
        $this->assertSame(2, $session->decrement('visits'));
    }

    public function test_all_excludes_flash_bookkeeping(): void
    {
        $session = session();
        $session->put('name', 'ada');
        $session->flash('status', 'ok');

        $all = $session->all();
        $this->assertArrayHasKey('name', $all);
        $this->assertArrayHasKey('status', $all);
        $this->assertArrayNotHasKey('_flash', $all);
    }

    public function test_flash_survives_exactly_one_request(): void
    {
        // Request 1: flash a value. It's available immediately.
        $r1 = new Session(false);
        $r1->flash('status', 'saved');
        $this->assertSame('saved', $r1->get('status'));

        // Request 2: a fresh instance sees the flashed value...
        $r2 = new Session(false);
        $this->assertSame('saved', $r2->get('status'));

        // Request 3: ...but by now it has been aged out.
        $r3 = new Session(false);
        $this->assertNull($r3->get('status'));
    }

    public function test_reflash_keeps_flash_for_another_request(): void
    {
        $r1 = new Session(false);
        $r1->flash('status', 'saved');

        $r2 = new Session(false);
        $r2->start();
        $r2->reflash();

        // Because r2 reflashed, r3 still sees it.
        $r3 = new Session(false);
        $this->assertSame('saved', $r3->get('status'));
    }
}
