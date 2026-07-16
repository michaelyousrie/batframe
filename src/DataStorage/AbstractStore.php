<?php

declare(strict_types=1);

namespace Batframe\DataStorage;

use JsonException;

/**
 * The parts of the {@see Store} contract that are the same no matter where the
 * bytes end up: what an id is, when a timestamp is written, what a collection
 * may be called, and what a bare criteria value means.
 *
 * Those are decisions the *framework* makes, not the driver, so they live here
 * rather than being re-derived (and eventually re-derived differently) by every
 * driver. What a driver owns is its own storage and its own dialect.
 *
 * Extending this is a convenience, not a requirement — the seam is `Store`, and
 * a driver is free to implement it directly. Extend it and you inherit these
 * rules for free.
 */
abstract class AbstractStore implements Store
{
    /**
     * @param (callable(): int)|null $clock Unix timestamp source, injectable so
     *                                      tests are deterministic.
     */
    public function __construct(
        protected $clock = null,
    ) {
    }

    // ------------------------------------------------------------------
    // Time
    // ------------------------------------------------------------------

    protected function now(): int
    {
        return $this->clock === null ? time() : ($this->clock)();
    }

    /**
     * Always UTC, so timestamps sort lexicographically — which is what makes
     * `orderBy: 'created_at'` mean what it looks like it means.
     */
    protected function timestamp(): string
    {
        return gmdate('Y-m-d\TH:i:sP', $this->now());
    }

    // ------------------------------------------------------------------
    // Records
    // ------------------------------------------------------------------

    /**
     * Fill in a record's id and timestamps, and settle its key order.
     *
     * Anything the caller supplied is kept verbatim: their id, their
     * created_at, their updated_at. $autoId is only consulted when there is no
     * id to keep.
     *
     * @param array<string, mixed> $record
     *
     * @return array<string, mixed>
     */
    protected function stamp(array $record, int|string $autoId): array
    {
        $now = $this->timestamp();

        $id = $record['id'] ?? $autoId;
        $createdAt = $record['created_at'] ?? $now;
        $updatedAt = $record['updated_at'] ?? $now;

        unset($record['id'], $record['created_at'], $record['updated_at']);

        // Rebuilt in a fixed order so a record looks the same however it was
        // handed in, and so a driver that round-trips it compares identical.
        return ['id' => $id] + $record + ['created_at' => $createdAt, 'updated_at' => $updatedAt];
    }

    /**
     * Merge an update into an existing record.
     *
     * The id is not a field you can assign: it identifies the record, and
     * letting an update move a record to a new identity would be a different
     * operation wearing this one's clothes. updated_at refreshes unless the
     * caller had an opinion.
     *
     * @param array<string, mixed> $record
     * @param array<string, mixed> $values
     *
     * @return array<string, mixed>
     */
    protected function merge(array $record, array $values): array
    {
        unset($values['id']);

        $merged = array_merge($record, $values);
        $merged['updated_at'] = $values['updated_at'] ?? $this->timestamp();

        return $merged;
    }

    /**
     * The next id: one past the highest integer id in use.
     *
     * Ids the caller supplied as strings are skipped, so an app that uses its
     * own keys does not disturb the counting. Nothing is persisted, so the id
     * of a deleted last record is handed out again — supply your own ids if
     * they must never repeat.
     *
     * @param list<int|string|null> $ids
     */
    protected function nextId(array $ids): int
    {
        $highest = 0;

        foreach ($ids as $id) {
            if (is_int($id) && $id > $highest) {
                $highest = $id;
            }
        }

        return $highest + 1;
    }

    /**
     * Encode a value for storage.
     *
     * Not every PHP value survives the trip — invalid UTF-8 is the usual
     * culprit, and it arrives from user input, not from a bug. The failure is
     * reported as a {@see DataStorageException} like any other storage failure,
     * rather than as a raw JsonException the caller has no reason to expect.
     *
     * **Call this before destroying anything.** A driver that truncates first
     * and encodes second turns one rejected record into a lost collection.
     *
     * @throws DataStorageException
     */
    protected function toJson(mixed $value, int $flags = 0): string
    {
        try {
            return json_encode($value, $flags | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new DataStorageException("This value cannot be stored as JSON: {$e->getMessage()}", $e);
        }
    }

    /**
     * The next id to hand out, having just stored $used.
     *
     * Lets a driver allocate ids across a batch without rescanning the whole
     * collection per record, while still landing where {@see nextId()} would:
     * an id the caller supplied moves the counter only if it is an integer at
     * or beyond it, and a string id does not consume anything.
     */
    protected function advance(int $next, int|string $used): int
    {
        if (is_int($used) && $used >= $next) {
            return $used + 1;
        }

        return $next;
    }

    // ------------------------------------------------------------------
    // Criteria
    // ------------------------------------------------------------------

    /**
     * Normalise a criteria group so a driver only ever sees {@see Is} objects.
     * A bare value is equality; that shorthand is resolved once, here, rather
     * than in every driver.
     *
     * @param array<string, mixed|Is> $group
     *
     * @return array<string, Is>
     */
    protected function criteria(array $group): array
    {
        $criteria = [];

        foreach ($group as $field => $value) {
            $criteria[$field] = $value instanceof Is ? $value : Is::equals($value);
        }

        return $criteria;
    }

    // ------------------------------------------------------------------
    // Names
    // ------------------------------------------------------------------

    /**
     * A collection name reaches a filename on one driver and an SQL identifier
     * on another. Rather than trust each driver to make that safe its own way,
     * the name is held to one conservative shape everywhere.
     *
     * @throws DataStorageException
     */
    protected function guardCollection(string $collection): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $collection) !== 1) {
            throw new DataStorageException(
                "'{$collection}' is not a legal collection name: use letters, numbers and underscores.",
            );
        }

        return $collection;
    }
}
