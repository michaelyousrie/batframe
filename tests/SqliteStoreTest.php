<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\DataStorage\Sqlite\SqliteStore;
use Batframe\DataStorage\Store;
use PDO;

/**
 * The whole Store contract, against the SQLite driver — plus the handful of
 * things that are only true of a database.
 */
final class SqliteStoreTest extends StoreContractTestCase
{
    private string $file;

    protected function setUp(): void
    {
        $this->file = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batframe-sqlite-test-' . getmypid() . '-' . uniqid() . '.sqlite';

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach ([$this->file, $this->file . '-wal', $this->file . '-shm'] as $file) {
            @unlink($file);
        }
    }

    protected function makeStore(): Store
    {
        // In memory: fast, and every test gets a database of its own.
        return new SqliteStore(':memory:', fn (): int => $this->now);
    }

    // ------------------------------------------------------------------
    // The database
    // ------------------------------------------------------------------

    public function test_a_collection_is_one_table_created_on_demand(): void
    {
        $store = new SqliteStore($this->file, fn (): int => $this->now);
        $store->insert('users', ['name' => 'Michael']);

        $tables = $this->connect()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        $this->assertContains('users', $tables);
        $this->assertNotContains('posts', $tables);
    }

    public function test_reading_never_creates_a_table(): void
    {
        $store = new SqliteStore($this->file, fn (): int => $this->now);
        $store->all('users');

        $tables = $this->connect()->query("SELECT name FROM sqlite_master WHERE type = 'table'")->fetchAll(PDO::FETCH_COLUMN);

        $this->assertNotContains('users', $tables);
    }

    public function test_records_persist_across_instances(): void
    {
        $write = new SqliteStore($this->file, fn (): int => $this->now);
        $write->insert('users', ['name' => 'Michael']);

        $read = new SqliteStore($this->file, fn (): int => $this->now);

        $this->assertSame('Michael', $read->get('users', 1)['name']);
    }

    public function test_id_types_survive_the_round_trip(): void
    {
        $store = new SqliteStore($this->file, fn (): int => $this->now);
        $store->insert('users', ['name' => 'auto']);
        $store->insert('users', ['id' => 'usr_ab12', 'name' => 'string']);

        // PDO binds everything as a string unless told otherwise, which would
        // quietly turn the integer id 1 into the text '1' and break both
        // typeof()-based id generation and get(1).
        $rows = $this->connect()->query('SELECT id, typeof(id) AS type FROM "users" ORDER BY rowid')->fetchAll(PDO::FETCH_ASSOC);

        $this->assertSame('integer', $rows[0]['type']);
        $this->assertSame('text', $rows[1]['type']);
    }

    public function test_the_connection_is_lazy(): void
    {
        $path = $this->file . '-never-touched';

        new SqliteStore($path, fn (): int => $this->now);

        // Constructing a store should not go near the disk.
        $this->assertFileDoesNotExist($path);
    }

    public function test_a_record_is_stored_as_one_json_document(): void
    {
        $store = new SqliteStore($this->file, fn (): int => $this->now);
        $store->insert('users', ['name' => 'Michael', 'age' => 36]);

        $data = $this->connect()->query('SELECT data FROM "users"')->fetchColumn();

        $this->assertSame($store->get('users', 1), json_decode($data, true));
    }

    private function connect(): PDO
    {
        return new PDO('sqlite:' . $this->file, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    }
}
