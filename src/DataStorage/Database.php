<?php

declare(strict_types=1);

namespace Batframe\DataStorage;

use Batframe\Batframe;
use Batframe\DataStorage\Json\JsonStore;

/**
 * Holds the store the `db()` helper reaches for.
 *
 * {@see Store} is an interface and an interface cannot hold state, so the
 * swappable-singleton pattern the rest of the framework uses — `instance()` and
 * `swap()`, as on Session, Cache and Validator — lives here instead.
 *
 * Resolution is lazy and goes through {@see Batframe::current()}, the same way
 * {@see \Batframe\Helpers\Cache} finds its directory. That means `db()` works
 * before an app has booted, works with no app at all, and lets a test swap in
 * its own store without an app anywhere in sight.
 */
final class Database
{
    private static ?Store $instance = null;

    public static function instance(): Store
    {
        return self::$instance ??= self::default();
    }

    /**
     * Swap the store. Pass null to reset, which is what a test's tearDown wants.
     */
    public static function swap(?Store $store): void
    {
        self::$instance = $store;
    }

    /**
     * The running app's store, or a JSON store under the system temp directory
     * when there is no app — so `db()` outside a request degrades to something
     * that works rather than to a null.
     */
    private static function default(): Store
    {
        $app = Batframe::current();

        if ($app !== null) {
            return $app->store();
        }

        return new JsonStore(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batframe' . DIRECTORY_SEPARATOR . 'database');
    }
}
