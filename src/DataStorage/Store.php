<?php

declare(strict_types=1);

namespace Batframe\DataStorage;

/**
 * A persistent store. **This is the swap point.**
 *
 * Every driver implements these methods with these exact signatures, so moving
 * an app from {@see Json\JsonStore} to {@see Sqlite\SqliteStore} is a config
 * change and nothing else. Adding a driver means implementing this interface
 * and, if the driver speaks a query language, a {@see CriteriaCompiler} to go
 * with it. No call site changes, and no existing driver is touched.
 *
 * The behaviour every driver owes the caller is pinned by one shared test case
 * (`tests/StoreContractTestCase.php`), which runs against each driver in turn.
 * "Swappable" is a claim that has to be proven, and that is where it is proven.
 *
 * Collections are schemaless: nothing to declare, no migration to run. A record
 * is an array; the store fills in `id`, `created_at` and `updated_at` unless the
 * caller supplied them.
 */
interface Store
{
    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * Store one record and return it as stored, including any id and
     * timestamps that were filled in.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     *
     * @throws DataStorageException if the id is already taken
     */
    public function insert(string $collection, array $record): array;

    /**
     * Store many records in one go.
     *
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    public function insertMany(string $collection, array $records): array;

    /**
     * Merge $values into the record with this id. Returns the updated record,
     * or null if there is no such record.
     *
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>|null
     */
    public function update(string $collection, int|string $id, array $values): ?array;

    /**
     * Merge $values into every matching record. Returns how many were updated.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed>         $values
     * @param array<string, mixed|Is>|null $or
     */
    public function updateWhere(string $collection, array $where, array $values, ?array $or = null): int;

    /**
     * Remove the record with this id. Returns whether there was one.
     */
    public function delete(string $collection, int|string $id): bool;

    /**
     * Remove every matching record. Returns how many were removed.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     */
    public function deleteWhere(string $collection, array $where, ?array $or = null): int;

    /**
     * Remove every record in the collection.
     */
    public function truncate(string $collection): void;

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * The record with this id, or null.
     *
     * @return array<string, mixed>|null
     */
    public function get(string $collection, int|string $id): ?array;

    /**
     * Every record, in insertion order.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $collection): array;

    /**
     * Every matching record.
     *
     * $where is AND across its entries; $or adds a second group OR'd with the
     * first, so the whole matcher reads `(all of $where) OR (all of $or)`.
     * A bare value means equality; an {@see Is} means anything richer.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return list<array<string, mixed>>
     */
    public function find(
        string $collection,
        array $where = [],
        ?array $or = null,
        ?string $orderBy = null,
        bool $desc = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * Every record the matcher does *not* select. Negates the matcher as a
     * whole, `$or` included.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return list<array<string, mixed>>
     */
    public function findNot(
        string $collection,
        array $where = [],
        ?array $or = null,
        ?string $orderBy = null,
        bool $desc = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array;

    /**
     * The first matching record, or null.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return array<string, mixed>|null
     */
    public function findOne(string $collection, array $where = [], ?array $or = null): ?array;

    /**
     * How many records match.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     */
    public function count(string $collection, array $where = [], ?array $or = null): int;

    /**
     * Whether anything matches.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     */
    public function exists(string $collection, array $where = [], ?array $or = null): bool;

    /**
     * The names of every collection that holds data.
     *
     * @return list<string>
     */
    public function collections(): array;
}
