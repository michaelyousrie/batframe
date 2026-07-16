<?php

declare(strict_types=1);

namespace Batframe\DataStorage\Json;

use Batframe\DataStorage\AbstractStore;
use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Is;
use Throwable;

/**
 * The default driver: one JSON file per collection, and nothing else.
 *
 * No server, no schema, no migration, no extension beyond ext-json. A fresh app
 * persists data with zero configuration, which is the whole reason this is the
 * default.
 *
 * The files are a plain, pretty-printed array of records that anyone can open
 * and read. That is a feature, not a formatting accident: being able to `cat`
 * your data and understand it is most of what this driver is for. It is also
 * why no auto-increment counter is kept anywhere — a bookkeeping key would turn
 * the file into a structure wrapping your records rather than your records.
 *
 * Filtering, ordering and paging happen in PHP, over the whole decoded
 * collection. Every write rewrites its file under an exclusive lock. Both facts
 * put a ceiling on this driver: it is built for a small collection and a modest
 * number of writers. When you outgrow that, {@see \Batframe\DataStorage\Sqlite\SqliteStore}
 * is the same interface with a real engine underneath — that is what swapping
 * is for.
 */
final class JsonStore extends AbstractStore
{
    private const EXTENSION = '.json';

    /** Sort ranks: nothing first, then numbers, then text. */
    private const SORT_NOTHING = 0;
    private const SORT_NUMBER = 1;
    private const SORT_TEXT = 2;

    private string $directory;

    /**
     * @param (callable(): int)|null $clock
     */
    public function __construct(string $directory, $clock = null)
    {
        parent::__construct($clock);

        $this->directory = rtrim($directory, '/\\');
    }

    // ------------------------------------------------------------------
    // Writing
    // ------------------------------------------------------------------

    public function insert(string $collection, array $record): array
    {
        return $this->insertMany($collection, [$record])[0];
    }

    public function insertMany(string $collection, array $records): array
    {
        return $this->write($collection, function (array $existing) use ($collection, $records): array {
            $inserted = [];

            // Allocated once and carried, rather than rescanning every id for
            // every record — that turns a bulk insert quadratic.
            $next = $this->nextId($this->pluckIds($existing));

            foreach ($records as $record) {
                $stamped = $this->stamp($record, $next);
                $next = $this->advance($next, $stamped['id']);

                if ($this->indexOf($existing, $stamped['id']) !== null) {
                    throw new DataStorageException(
                        "A record with the id '{$stamped['id']}' is already in the collection '{$collection}'.",
                    );
                }

                $existing[] = $stamped;
                $inserted[] = $stamped;
            }

            return [$existing, $inserted];
        });
    }

    public function update(string $collection, int|string $id, array $values): ?array
    {
        return $this->write($collection, function (array $records) use ($id, $values): array {
            $index = $this->indexOf($records, $id);

            if ($index === null) {
                // Nothing changed, so nothing is written.
                return [null, null];
            }

            $records[$index] = $this->merge($records[$index], $values);

            return [$records, $records[$index]];
        }, mayCreate: false);
    }

    public function updateWhere(string $collection, array $where, array $values, ?array $or = null): int
    {
        return $this->write($collection, function (array $records) use ($where, $values, $or): array {
            $updated = 0;

            foreach ($records as $index => $record) {
                if ($this->matches($record, $where, $or)) {
                    $records[$index] = $this->merge($record, $values);
                    $updated++;
                }
            }

            return [$updated === 0 ? null : $records, $updated];
        }, mayCreate: false);
    }

    public function delete(string $collection, int|string $id): bool
    {
        return $this->write($collection, function (array $records) use ($id): array {
            $index = $this->indexOf($records, $id);

            if ($index === null) {
                return [null, false];
            }

            unset($records[$index]);

            return [array_values($records), true];
        }, mayCreate: false);
    }

    public function deleteWhere(string $collection, array $where, ?array $or = null): int
    {
        return $this->write($collection, function (array $records) use ($where, $or): array {
            $kept = [];
            $deleted = 0;

            foreach ($records as $record) {
                if ($this->matches($record, $where, $or)) {
                    $deleted++;

                    continue;
                }

                $kept[] = $record;
            }

            return [$deleted === 0 ? null : $kept, $deleted];
        }, mayCreate: false);
    }

    public function truncate(string $collection): void
    {
        $this->guardCollection($collection);

        // The collection is gone, so its file goes with it rather than being
        // left behind as an empty array.
        @unlink($this->pathFor($collection));
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    public function get(string $collection, int|string $id): ?array
    {
        $records = $this->read($collection);
        $index = $this->indexOf($records, $id);

        return $index === null ? null : $records[$index];
    }

    public function all(string $collection): array
    {
        return $this->read($collection);
    }

    public function find(
        string $collection,
        array $where = [],
        ?array $or = null,
        ?string $orderBy = null,
        bool $desc = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        return $this->select($collection, $where, $or, false, $orderBy, $desc, $limit, $offset);
    }

    public function findNot(
        string $collection,
        array $where = [],
        ?array $or = null,
        ?string $orderBy = null,
        bool $desc = false,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        return $this->select($collection, $where, $or, true, $orderBy, $desc, $limit, $offset);
    }

    public function findOne(string $collection, array $where = [], ?array $or = null): ?array
    {
        return $this->find($collection, $where, $or, limit: 1)[0] ?? null;
    }

    public function count(string $collection, array $where = [], ?array $or = null): int
    {
        return count($this->find($collection, $where, $or));
    }

    public function exists(string $collection, array $where = [], ?array $or = null): bool
    {
        return $this->findOne($collection, $where, $or) !== null;
    }

    public function collections(): array
    {
        $names = [];

        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*' . self::EXTENSION) ?: [] as $path) {
            // An empty file holds no records, so it is not a collection. One
            // can be left behind by a write that failed before it wrote
            // anything, and it should not haunt the list afterwards.
            if (filesize($path) === 0) {
                continue;
            }

            $names[] = basename($path, self::EXTENSION);
        }

        return $names;
    }

    // ------------------------------------------------------------------
    // Querying
    // ------------------------------------------------------------------

    /**
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return list<array<string, mixed>>
     */
    private function select(
        string $collection,
        array $where,
        ?array $or,
        bool $negated,
        ?string $orderBy,
        bool $desc,
        ?int $limit,
        ?int $offset,
    ): array {
        $matched = [];

        foreach ($this->read($collection) as $record) {
            if ($this->matches($record, $where, $or) !== $negated) {
                $matched[] = $record;
            }
        }

        $matched = $this->sort($matched, $orderBy, $desc);

        if ($limit !== null || $offset !== null) {
            $matched = array_slice($matched, $offset ?? 0, $limit);
        }

        return $matched;
    }

    /**
     * `(all of $where) OR (all of $or)`.
     *
     * @param array<string, mixed>         $record
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     */
    private function matches(array $record, array $where, ?array $or): bool
    {
        if ($this->matchesGroup($record, $where)) {
            return true;
        }

        return $or !== null && $this->matchesGroup($record, $or);
    }

    /**
     * @param array<string, mixed>    $record
     * @param array<string, mixed|Is> $group
     */
    private function matchesGroup(array $record, array $group): bool
    {
        foreach ($this->criteria($group) as $field => $is) {
            // A missing field is null, which is the same value the SQL drivers
            // see for it, so Is only has to decide about null once.
            if (!$is->matches($record[$field] ?? null)) {
                return false;
            }
        }

        // An empty group is a vacuous AND: it selects everything.
        return true;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<array<string, mixed>>
     */
    private function sort(array $records, ?string $orderBy, bool $desc): array
    {
        if ($orderBy === null) {
            return $records;
        }

        // usort is stable, so records that tie keep the order they were
        // inserted in — the SQL drivers tie-break on insertion order to match.
        usort($records, function (array $a, array $b) use ($orderBy, $desc): int {
            $comparison = $this->compare($a[$orderBy] ?? null, $b[$orderBy] ?? null);

            return $desc ? -$comparison : $comparison;
        });

        return $records;
    }

    /**
     * The contract's ordering: nothing first, then numbers by value, then text.
     *
     * PHP's own `<=>` cannot be used directly for any of the three, and each
     * way it differs is a way the drivers would silently disagree:
     *
     *   - `null <=> -5` calls null the larger, because it compares null as 0.
     *     A missing value is not zero; it sorts before everything.
     *   - `'9' <=> '10'` reads two numeric strings as numbers and says '10' is
     *     the larger. They are text, and text sorts by character.
     *   - `true <=> 2` treats the comparison as boolean. A stored boolean is
     *     1 or 0 and sorts among the numbers.
     */
    private function compare(mixed $a, mixed $b): int
    {
        $rank = $this->sortRank($a);

        if ($rank !== $this->sortRank($b)) {
            return $rank <=> $this->sortRank($b);
        }

        return match ($rank) {
            self::SORT_NOTHING => 0,
            self::SORT_NUMBER => (float) $a <=> (float) $b,
            default => strcmp($this->sortText($a), $this->sortText($b)),
        };
    }

    private function sortRank(mixed $value): int
    {
        return match (true) {
            $value === null => self::SORT_NOTHING,
            is_bool($value), is_int($value), is_float($value) => self::SORT_NUMBER,
            default => self::SORT_TEXT,
        };
    }

    /**
     * Anything that is not a number sorts as text — and a structured value
     * sorts as the JSON it is stored as, which is what a driver holding records
     * as JSON sees when it sorts. Ordering by a field full of arrays is a
     * strange thing to ask for; agreeing on the answer still costs nothing.
     */
    private function sortText(mixed $value): string
    {
        return is_string($value)
            ? $value
            : $this->toJson($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function indexOf(array $records, int|string $id): ?int
    {
        foreach ($records as $index => $record) {
            // Strict, so the integer 1 and the string '1' stay different records.
            if (($record['id'] ?? null) === $id) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<int|string|null>
     */
    private function pluckIds(array $records): array
    {
        return array_map(static fn (array $record): int|string|null => $record['id'] ?? null, $records);
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    /**
     * Read a collection. Nothing is created: an unknown collection is empty,
     * not an error, and asking about one leaves no trace on disk.
     *
     * @return list<array<string, mixed>>
     */
    private function read(string $collection): array
    {
        $this->guardCollection($collection);

        $path = $this->pathFor($collection);

        if (!is_file($path)) {
            return [];
        }

        $handle = @fopen($path, 'r');

        if ($handle === false) {
            throw new DataStorageException("The collection '{$collection}' could not be opened for reading.");
        }

        try {
            flock($handle, LOCK_SH);
            $contents = stream_get_contents($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $this->decode($collection, $contents === false ? '' : $contents);
    }

    /**
     * Run a read-modify-write under one exclusive lock, so a concurrent writer
     * cannot interleave between the read and the write and lose a record.
     *
     * The callback receives the current records and returns
     * `[$recordsToWrite, $returnValue]`. Null records mean nothing changed, and
     * nothing is written.
     *
     * @param callable(list<array<string, mixed>>): array{0: list<array<string, mixed>>|null, 1: mixed} $mutate
     * @param bool $mayCreate Whether this operation can bring the collection
     *                        into existence. Only inserting can.
     */
    private function write(string $collection, callable $mutate, bool $mayCreate = true): mixed
    {
        $this->guardCollection($collection);

        $path = $this->pathFor($collection);

        if (!$mayCreate && !is_file($path)) {
            // Nothing stored, so there is nothing to change and no reason to
            // open a file — let alone create one. Running the mutation against
            // an empty collection yields the same "found nothing" answer it
            // would have given, without inventing a collection to hold it.
            return $mutate([])[1];
        }

        $this->ensureDirectory();

        $handle = @fopen($path, 'c+');

        if ($handle === false) {
            throw new DataStorageException("The collection '{$collection}' could not be opened for writing.");
        }

        try {
            flock($handle, LOCK_EX);

            $contents = stream_get_contents($handle);
            $records = $this->decode($collection, $contents === false ? '' : $contents);

            [$updated, $result] = $mutate($records);

            if ($updated !== null) {
                // Encode first. Everything that can fail — invalid UTF-8 above
                // all — must fail while the file on disk is still the good one.
                // Truncating before encoding would turn a rejected record into
                // an erased collection.
                $payload = $this->encode($updated);

                ftruncate($handle, 0);
                rewind($handle);

                $written = fwrite($handle, $payload);

                if ($written !== strlen($payload)) {
                    throw new DataStorageException(
                        "The collection '{$collection}' was only partially written; the disk may be full.",
                    );
                }

                fflush($handle);
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        return $result;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function decode(string $collection, string $contents): array
    {
        if (trim($contents) === '') {
            return [];
        }

        try {
            $records = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new DataStorageException(
                "The collection '{$collection}' is not readable JSON: {$e->getMessage()}",
                $e,
            );
        }

        if (!is_array($records)) {
            throw new DataStorageException("The collection '{$collection}' does not hold a list of records.");
        }

        // A corrupt file is never quietly treated as an empty collection. A
        // cache can shrug off a bad entry because a miss costs nothing; losing
        // someone's records and reporting "no data" looks identical to their
        // data never having existed.
        return array_values($records);
    }

    /**
     * @param list<array<string, mixed>> $records
     */
    private function encode(array $records): string
    {
        return $this->toJson(
            array_values($records),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";
    }

    private function pathFor(string $collection): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . $collection . self::EXTENSION;
    }

    private function ensureDirectory(): void
    {
        if (!is_dir($this->directory)) {
            @mkdir($this->directory, 0777, true);
        }
    }
}
