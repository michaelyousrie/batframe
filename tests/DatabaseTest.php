<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Batframe;
use Batframe\DataStorage\Collection;
use Batframe\DataStorage\Database;
use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Json\JsonStore;
use Batframe\DataStorage\Sqlite\SqliteStore;
use Batframe\DataStorage\Store;
use PHPUnit\Framework\TestCase;

final class DatabaseTest extends TestCase
{
    protected function setUp(): void
    {
        Database::swap(new SqliteStore(':memory:'));
    }

    protected function tearDown(): void
    {
        Database::swap(null);

        unset($_ENV['DB_DRIVER'], $_ENV['DB_PATH']);
    }

    // ------------------------------------------------------------------
    // The db() helper
    // ------------------------------------------------------------------

    public function test_helper_no_arg_returns_the_store(): void
    {
        $this->assertInstanceOf(Store::class, db());
        $this->assertSame(Database::instance(), db());
    }

    public function test_helper_with_a_name_returns_that_collection(): void
    {
        $users = db('users');

        $this->assertInstanceOf(Collection::class, $users);
        $this->assertSame('users', $users->name());
    }

    public function test_the_helper_reads_and_writes_through_the_swapped_store(): void
    {
        db('users')->insert(['name' => 'Michael']);

        $this->assertSame('Michael', Database::instance()->get('users', 1)['name']);
        $this->assertSame(1, db('users')->count());
    }

    // ------------------------------------------------------------------
    // Resolving a driver
    // ------------------------------------------------------------------

    public function test_it_defaults_to_json_with_no_app_and_no_config(): void
    {
        Database::swap(null);

        // Nothing configured anywhere: it still works, which is the point.
        $this->assertInstanceOf(JsonStore::class, Database::instance());
    }

    public function test_an_app_supplies_the_store(): void
    {
        $app = $this->app(['database' => 'sqlite', 'database_path' => ':memory:']);
        $app->boot();

        try {
            Database::swap(null);

            $this->assertInstanceOf(SqliteStore::class, Database::instance());
            $this->assertSame($app->store(), Database::instance());
        } finally {
            $this->unboot();
        }
    }

    public function test_a_store_instance_can_be_injected_as_config(): void
    {
        $store = new SqliteStore(':memory:');
        $app = $this->app(['database' => $store]);

        $this->assertSame($store, $app->store());
    }

    public function test_the_driver_comes_from_the_environment_when_config_is_silent(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';
        $_ENV['DB_PATH'] = ':memory:';

        // Local on JSON, production on SQLite, without touching the code.
        $this->assertInstanceOf(SqliteStore::class, $this->app()->store());
    }

    public function test_config_beats_the_environment(): void
    {
        $_ENV['DB_DRIVER'] = 'sqlite';

        $this->assertInstanceOf(JsonStore::class, $this->app(['database' => 'json'])->store());
    }

    public function test_an_unknown_driver_names_the_ones_that_exist(): void
    {
        try {
            $this->app(['database' => 'mongo'])->store();
            $this->fail('Expected DataStorageException');
        } catch (DataStorageException $e) {
            $this->assertStringContainsString('mongo', $e->getMessage());
            $this->assertStringContainsString('json', $e->getMessage());
            $this->assertStringContainsString('sqlite', $e->getMessage());
        }
    }

    public function test_the_store_is_built_once(): void
    {
        $app = $this->app(['database' => 'sqlite', 'database_path' => ':memory:']);

        $this->assertSame($app->store(), $app->store());
    }

    // ------------------------------------------------------------------
    // Paths
    // ------------------------------------------------------------------

    public function test_the_default_paths_suit_the_driver(): void
    {
        // A directory of files for JSON, a single file for SQLite. Both sit
        // under storage/, next to the cache, resolved the same way it is.
        $this->assertSame(
            $this->basePath() . DIRECTORY_SEPARATOR . 'storage/database',
            $this->app(['database' => 'json'])->databasePath(),
        );

        $this->assertSame(
            $this->basePath() . DIRECTORY_SEPARATOR . 'storage/database.sqlite',
            $this->app(['database' => 'sqlite'])->databasePath(),
        );
    }

    public function test_a_relative_database_path_resolves_against_the_base_path(): void
    {
        $app = $this->app(['database_path' => 'data/records']);

        $this->assertSame(
            $this->basePath() . DIRECTORY_SEPARATOR . 'data/records',
            $app->databasePath(),
        );
    }

    public function test_an_absolute_database_path_is_left_alone(): void
    {
        $absolute = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batframe-db-abs';

        $this->assertSame($absolute, $this->app(['database_path' => $absolute])->databasePath());
    }

    public function test_the_path_comes_from_the_environment_too(): void
    {
        $_ENV['DB_PATH'] = 'from/env';

        $this->assertSame(
            $this->basePath() . DIRECTORY_SEPARATOR . 'from/env',
            $this->app()->databasePath(),
        );
    }

    /**
     * @param array<string, mixed> $config
     */
    private function app(array $config = []): Batframe
    {
        return new class (['base_path' => $this->basePath()] + $config) extends Batframe {};
    }

    private function basePath(): string
    {
        return sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batframe-db-app';
    }

    private function unboot(): void
    {
        // boot() parks the app on a static; leaving it there would leak into
        // every test that resolves a default afterwards.
        $reflection = new \ReflectionProperty(Batframe::class, 'current');
        $reflection->setValue(null, null);
    }
}
