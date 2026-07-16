<?php

declare(strict_types=1);

namespace Batframe\DataStorage;

/**
 * One collection in a {@see Store}: what `db('users')` hands you.
 *
 * It is the Store's API with the collection name already filled in, so you say
 * `db('users')->find(...)` instead of `db()->find('users', ...)`. That is all it
 * is. It holds no state, makes no decisions, and contains no driver-specific
 * code — which is exactly why it cannot behave differently from one driver to
 * the next.
 *
 * @see Store for what each method means.
 */
final class Collection
{
    public function __construct(
        private Store $store,
        private string $name,
    ) {
    }

    public function name(): string
    {
        return $this->name;
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    public function insert(array $record): array
    {
        return $this->store->insert($this->name, $record);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    public function insertMany(array $records): array
    {
        return $this->store->insertMany($this->name, $records);
    }

    /**
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>|null
     */
    public function update(int|string $id, array $values): ?array
    {
        return $this->store->update($this->name, $id, $values);
    }

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed>         $values
     * @param array<string, mixed|Is>|null $or
     */
    public function updateWhere(array $where, array $values, ?array $or = null): int
    {
        return $this->store->updateWhere($this->name, $where, $values, $or);
    }

    public function delete(int|string $id): bool
    {
        return $this->store->delete($this->name, $id);
    }

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     */
    public function deleteWhere(array $where, ?array $or = null): int
    {
        return $this->store->deleteWhere($this->name, $where, $or);
    }

    public function truncate(): void
    {
        $this->store->truncate($this->name);
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>|null
     */
    public function get(int|string $id): ?array
    {
        return $this->store->get($this->name, $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return $this->store->all($this->name);
    }

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return list<array<string, mixed>>
     */
    public function find(
        array $where = [],
        ?array $or = null,
        ?string $orderBy = null,
        bool $desc = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        return $this->store->find($this->name, $where, $or, $orderBy, $desc, $limit, $offset);
    }

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return list<array<string, mixed>>
     */
    public function findNot(
        array $where = [],
        ?array $or = null,
        ?string $orderBy = null,
        bool $desc = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        return $this->store->findNot($this->name, $where, $or, $orderBy, $desc, $limit, $offset);
    }

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return array<string, mixed>|null
     */
    public function findOne(array $where = [], ?array $or = null): ?array
    {
        return $this->store->findOne($this->name, $where, $or);
    }

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     */
    public function count(array $where = [], ?array $or = null): int
    {
        return $this->store->count($this->name, $where, $or);
    }

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     */
    public function exists(array $where = [], ?array $or = null): bool
    {
        return $this->store->exists($this->name, $where, $or);
    }
}
