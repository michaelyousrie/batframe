<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\DataStorage\Collection;
use Batframe\DataStorage\Is;
use Batframe\DataStorage\Sqlite\SqliteStore;
use Batframe\DataStorage\Store;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Collection is what db('users') hands back: the Store's API with the
 * collection name already filled in. It has no behaviour of its own, so what
 * matters is that every call lands on the store against the right collection.
 */
final class CollectionTest extends TestCase
{
    private Store $store;

    private Collection $users;

    protected function setUp(): void
    {
        $this->store = new SqliteStore(':memory:', fn (): int => 1_000_000);
        $this->users = new Collection($this->store, 'users');
    }

    public function test_writes_land_in_the_named_collection(): void
    {
        $user = $this->users->insert(['name' => 'Michael']);

        $this->assertSame($user, $this->store->get('users', 1));
    }

    public function test_insert_many(): void
    {
        $this->users->insertMany([['name' => 'a'], ['name' => 'b']]);

        $this->assertSame(2, $this->store->count('users'));
    }

    public function test_reads_come_from_the_named_collection(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);
        $this->store->insert('posts', ['title' => 'Not a user']);

        $this->assertSame('Michael', $this->users->get(1)['name']);
        $this->assertCount(1, $this->users->all());
        $this->assertCount(1, $this->users->find());
        $this->assertSame(1, $this->users->count());
        $this->assertTrue($this->users->exists());
        $this->assertSame('Michael', $this->users->findOne(['name' => 'Michael'])['name']);
    }

    public function test_find_passes_every_argument_through(): void
    {
        $this->store->insertMany('users', [
            ['name' => 'Michael', 'age' => 36],
            ['name' => 'F0rty', 'age' => 17],
            ['name' => 'Ada', 'age' => 36],
        ]);

        $found = $this->users->find(
            ['age' => Is::greaterThan(18)],
            or: ['name' => 'F0rty'],
            orderBy: 'name',
            desc: true,
            limit: 2,
            offset: 1,
        );

        $this->assertSame(
            ['F0rty', 'Ada'],
            array_map(static fn (array $u): string => $u['name'], $found),
        );
    }

    public function test_find_not_passes_every_argument_through(): void
    {
        $this->store->insertMany('users', [
            ['name' => 'Michael'],
            ['name' => 'F0rty'],
            ['name' => 'Ada'],
        ]);

        $found = $this->users->findNot(['name' => 'Michael'], orderBy: 'name');

        $this->assertSame(
            ['Ada', 'F0rty'],
            array_map(static fn (array $u): string => $u['name'], $found),
        );
    }

    public function test_update_and_delete(): void
    {
        $this->users->insert(['name' => 'Michael']);

        $this->assertSame('Mike', $this->users->update(1, ['name' => 'Mike'])['name']);
        $this->assertSame(1, $this->users->updateWhere(['name' => 'Mike'], ['role' => 'admin']));
        $this->assertSame('admin', $this->store->get('users', 1)['role']);

        $this->assertTrue($this->users->delete(1));
        $this->assertSame(0, $this->store->count('users'));
    }

    public function test_delete_where_and_truncate(): void
    {
        $this->users->insertMany([['name' => 'a'], ['name' => 'b']]);

        $this->assertSame(1, $this->users->deleteWhere(['name' => 'a']));

        $this->users->truncate();
        $this->assertSame([], $this->store->all('users'));
    }

    public function test_it_knows_its_own_name(): void
    {
        $this->assertSame('users', $this->users->name());
    }

    public function test_it_exposes_every_store_method_but_the_collection_name(): void
    {
        // The facade is only useful if it is complete: a method that exists on
        // the Store but not here sends people back to db()->method('users', …)
        // for no reason. This is what catches a method added to the interface
        // and forgotten here.
        $skip = ['collections'];

        foreach ((new ReflectionClass(Store::class))->getMethods() as $method) {
            if (in_array($method->getName(), $skip, true)) {
                continue;
            }

            $this->assertTrue(
                method_exists(Collection::class, $method->getName()),
                "Collection is missing Store::{$method->getName()}()",
            );

            $onCollection = new ReflectionMethod(Collection::class, $method->getName());

            $this->assertSame(
                $method->getNumberOfParameters() - 1,
                $onCollection->getNumberOfParameters(),
                "Collection::{$method->getName()}() should take the Store's parameters minus the collection name",
            );
        }
    }
}
