<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Json\JsonStore;
use Batframe\DataStorage\Store;

/**
 * The whole Store contract, against the JSON driver — plus the handful of
 * things that are only true of files.
 */
final class JsonStoreTest extends StoreContractTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'batframe-json-test-' . getmypid() . '-' . uniqid();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            @unlink($file);
        }

        @rmdir($this->dir);
    }

    protected function makeStore(): Store
    {
        return new JsonStore($this->dir, fn (): int => $this->now);
    }

    // ------------------------------------------------------------------
    // Files
    // ------------------------------------------------------------------

    public function test_a_collection_is_one_readable_file(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);

        $path = $this->dir . DIRECTORY_SEPARATOR . 'users.json';
        $this->assertFileExists($path);

        // Being able to open the file and read it is the point of this driver,
        // so the formatting is behaviour, not decoration.
        $contents = file_get_contents($path);
        $this->assertStringContainsString("[\n", $contents);
        $this->assertStringContainsString('"name": "Michael"', $contents);

        $this->assertSame([$this->store->get('users', 1)], json_decode($contents, true));
    }

    public function test_the_directory_is_created_on_demand(): void
    {
        $this->assertDirectoryDoesNotExist($this->dir);

        $this->store->insert('users', ['name' => 'Michael']);

        $this->assertDirectoryExists($this->dir);
    }

    public function test_reading_never_creates_anything(): void
    {
        $this->assertSame([], $this->store->all('users'));

        $this->assertDirectoryDoesNotExist($this->dir);
    }

    public function test_records_persist_across_instances(): void
    {
        // Write with one instance...
        $this->makeStore()->insert('users', ['name' => 'Michael']);

        // ...and read it back with a fresh one over the same directory.
        $this->assertSame('Michael', $this->makeStore()->get('users', 1)['name']);
    }

    public function test_a_corrupt_file_is_loud(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);
        file_put_contents($this->dir . DIRECTORY_SEPARATOR . 'users.json', '{not json');

        // A cache treats a corrupt entry as a miss, because a miss is free.
        // Data is not: quietly reporting an empty collection would look exactly
        // like someone's records having never existed.
        try {
            $this->store->all('users');
            $this->fail('Expected DataStorageException');
        } catch (DataStorageException $e) {
            $this->assertStringContainsString('users', $e->getMessage());
        }
    }

    public function test_truncate_removes_the_file(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);
        $this->store->truncate('users');

        $this->assertFileDoesNotExist($this->dir . DIRECTORY_SEPARATOR . 'users.json');
        $this->assertSame([], $this->store->collections());
    }
}
