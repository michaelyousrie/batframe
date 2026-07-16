<?php

declare(strict_types=1);

namespace Batframe\DataStorage\Sqlite;

use Batframe\DataStorage\AbstractStore;
use Batframe\DataStorage\CriteriaCompiler;
use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Is;
use PDO;
use PDOException;
use Throwable;

/**
 * The same store, over SQLite: for when the scope is still small but the engine
 * needs to be real. Real transactions, real concurrent writers, and queries
 * that do not read the whole collection into memory to answer.
 *
 * It is schemaless all the same. A collection is a table created on first
 * write, holding the record as a single JSON document, and filtering is pushed
 * down into `json_extract`. There is still nothing to declare and no migration
 * to run — swapping to this driver is a config change, not a project.
 *
 * Two decisions are worth knowing about:
 *
 *   - **The record comes back through `json_decode`**, exactly as it does in
 *     the JSON driver. Only the WHERE runs in SQL. That is what keeps a 36 an
 *     integer on both drivers instead of the string PDO would hand back.
 *   - **The id column is deliberately typeless**, and ids are computed rather
 *     than left to AUTOINCREMENT. A typeless column is the one kind SQLite will
 *     not coerce, so `1` stays an integer and `'usr_ab12'` stays text; and
 *     computing the next id is what lets this driver follow the same rule as
 *     the JSON one instead of inventing its own.
 */
final class SqliteStore extends AbstractStore
{
    /** How long to wait on a busy database before failing, in milliseconds. */
    private const BUSY_TIMEOUT_MS = 5000;

    private ?PDO $connection = null;

    private SqliteCompiler $compiler;

    /**
     * @param string                 $path     A file, or ':memory:'.
     * @param (callable(): int)|null $clock
     * @param SqliteCompiler|null    $compiler Subclass it to teach this driver a
     *                                         new operator. It is deliberately
     *                                         not any {@see CriteriaCompiler}:
     *                                         an SQLite store speaking another
     *                                         dialect is not a thing, and the
     *                                         swap point is the Store.
     */
    public function __construct(
        private string $path,
        $clock = null,
        ?SqliteCompiler $compiler = null,
    ) {
        parent::__construct($clock);

        $this->compiler = $compiler ?? new SqliteCompiler();
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
        $this->guardCollection($collection);

        return $this->transaction(function () use ($collection, $records): array {
            $this->createTable($collection);

            $inserted = [];

            // Read the ids once for the whole batch, not once per record.
            $next = $this->nextId($this->ids($collection));

            foreach ($records as $record) {
                $stamped = $this->stamp($record, $next);
                $next = $this->advance($next, $stamped['id']);

                try {
                    $this->run(
                        "INSERT INTO \"{$collection}\" (id, data) VALUES (?, ?)",
                        [$stamped['id'], $this->encode($stamped)],
                    );
                } catch (PDOException $e) {
                    // The primary key is what catches a duplicate; the JSON
                    // driver has to check for one by hand to say the same thing.
                    // Only a constraint violation means that, though — a full
                    // disk or a read-only file raises PDOException too, and
                    // reporting those as an id clash would send someone hunting
                    // for a conflict that does not exist.
                    if (!$this->isConstraintViolation($e)) {
                        throw new DataStorageException(
                            "The collection '{$collection}' could not be written: {$e->getMessage()}",
                            $e,
                        );
                    }

                    throw new DataStorageException(
                        "A record with the id '{$stamped['id']}' is already in the collection '{$collection}'.",
                        $e,
                    );
                }

                $inserted[] = $stamped;
            }

            return $inserted;
        });
    }

    public function update(string $collection, int|string $id, array $values): ?array
    {
        $this->guardCollection($collection);

        return $this->transaction(function () use ($collection, $id, $values): ?array {
            $record = $this->get($collection, $id);

            if ($record === null) {
                return null;
            }

            $merged = $this->merge($record, $values);

            $this->run("UPDATE \"{$collection}\" SET data = ? WHERE id IS ?", [$this->encode($merged), $id]);

            return $merged;
        });
    }

    public function updateWhere(string $collection, array $where, array $values, ?array $or = null): int
    {
        $this->guardCollection($collection);

        return $this->transaction(function () use ($collection, $where, $values, $or): int {
            $updated = 0;

            // Read then write, rather than one UPDATE ... WHERE: merging is the
            // framework's rule, not SQLite's, and running it in PHP is what
            // keeps the two drivers merging identically.
            foreach ($this->find($collection, $where, $or) as $record) {
                $merged = $this->merge($record, $values);

                $this->run(
                    "UPDATE \"{$collection}\" SET data = ? WHERE id IS ?",
                    [$this->encode($merged), $record['id']],
                );

                $updated++;
            }

            return $updated;
        });
    }

    public function delete(string $collection, int|string $id): bool
    {
        $this->guardCollection($collection);

        if (!$this->tableExists($collection)) {
            return false;
        }

        return $this->run("DELETE FROM \"{$collection}\" WHERE id IS ?", [$id])->rowCount() > 0;
    }

    public function deleteWhere(string $collection, array $where, ?array $or = null): int
    {
        $this->guardCollection($collection);

        if (!$this->tableExists($collection)) {
            return 0;
        }

        [$condition, $bindings] = $this->matcher($where, $or, false);

        return $this->run("DELETE FROM \"{$collection}\" WHERE {$condition}", $bindings)->rowCount();
    }

    public function truncate(string $collection): void
    {
        $this->guardCollection($collection);

        if ($this->tableExists($collection)) {
            $this->run("DROP TABLE \"{$collection}\"");
        }
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    public function get(string $collection, int|string $id): ?array
    {
        $this->guardCollection($collection);

        if (!$this->tableExists($collection)) {
            return null;
        }

        // IS rather than =, so the integer 1 and the string '1' stay different
        // records, the way they do in the JSON driver.
        $data = $this->run("SELECT data FROM \"{$collection}\" WHERE id IS ?", [$id])->fetchColumn();

        return $data === false ? null : $this->decode($collection, $data);
    }

    public function all(string $collection): array
    {
        return $this->find($collection);
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
        $this->guardCollection($collection);

        if (!$this->tableExists($collection)) {
            return 0;
        }

        [$condition, $bindings] = $this->matcher($where, $or, false);

        return (int) $this->run("SELECT COUNT(*) FROM \"{$collection}\" WHERE {$condition}", $bindings)->fetchColumn();
    }

    public function exists(string $collection, array $where = [], ?array $or = null): bool
    {
        return $this->findOne($collection, $where, $or) !== null;
    }

    public function collections(): array
    {
        $statement = $this->run(
            "SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name",
        );

        return $statement->fetchAll(PDO::FETCH_COLUMN);
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
        $this->guardCollection($collection);

        if (!$this->tableExists($collection)) {
            return [];
        }

        [$condition, $bindings] = $this->matcher($where, $or, $negated);

        $sql = "SELECT data FROM \"{$collection}\" WHERE {$condition}";

        if ($orderBy !== null) {
            $direction = $desc ? 'DESC' : 'ASC';
            // rowid is insertion order, and it breaks ties so that records
            // which sort equally come back in the order they were inserted.
            // Without it SQLite is free to order ties however it likes, and the
            // JSON driver's stable sort would quietly disagree.
            $sql .= " ORDER BY json_extract(data, ?) {$direction}, rowid ASC";
            $bindings[] = $this->compiler->path($orderBy);
        }

        if ($limit !== null || $offset !== null) {
            // OFFSET is only legal after a LIMIT, so an unbounded query with an
            // offset needs a limit that means "all of them".
            $sql .= ' LIMIT ? OFFSET ?';
            $bindings[] = $limit ?? -1;
            $bindings[] = $offset ?? 0;
        }

        $records = [];

        foreach ($this->run($sql, $bindings)->fetchAll(PDO::FETCH_COLUMN) as $data) {
            $records[] = $this->decode($collection, $data);
        }

        return $records;
    }

    /**
     * Compile `(all of $where) OR (all of $or)`, optionally negated.
     *
     * @param array<string, mixed|Is>      $where
     * @param array<string, mixed|Is>|null $or
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function matcher(array $where, ?array $or, bool $negated): array
    {
        [$condition, $bindings] = $this->group($where);

        if ($or !== null) {
            [$orCondition, $orBindings] = $this->group($or);

            $condition = "({$condition}) OR ({$orCondition})";
            $bindings = [...$bindings, ...$orBindings];
        }

        if ($negated) {
            $condition = "NOT ({$condition})";
        }

        return [$condition, $bindings];
    }

    /**
     * @param array<string, mixed|Is> $group
     *
     * @return array{0: string, 1: list<mixed>}
     */
    private function group(array $group): array
    {
        $fragments = [];
        $bindings = [];

        foreach ($this->criteria($group) as $field => $is) {
            [$fragment, $criterionBindings] = $this->compiler->compile($field, $is);

            $fragments[] = $fragment;
            $bindings = [...$bindings, ...$criterionBindings];
        }

        // An empty group is a vacuous AND: it selects everything.
        return [$fragments === [] ? '1' : implode(' AND ', $fragments), $bindings];
    }

    /**
     * The ids already in use, so the next one can follow the same rule the JSON
     * driver follows.
     *
     * @return list<int|string|null>
     */
    private function ids(string $collection): array
    {
        return $this->run("SELECT id FROM \"{$collection}\"")->fetchAll(PDO::FETCH_COLUMN);
    }

    // ------------------------------------------------------------------
    // Storage
    // ------------------------------------------------------------------

    /**
     * Connect on first use. Constructing a store should not touch the disk, so
     * that building one you never query costs nothing.
     */
    private function connection(): PDO
    {
        if ($this->connection !== null) {
            return $this->connection;
        }

        try {
            $connection = new PDO('sqlite:' . $this->path, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            throw new DataStorageException("The database at '{$this->path}' could not be opened: {$e->getMessage()}", $e);
        }

        // Readers do not block the writer, which is most of the reason to
        // reach for this driver over the JSON one.
        if ($this->path !== ':memory:') {
            $connection->exec('PRAGMA journal_mode = WAL');
        }

        // How long a writer waits for another writer before giving up. Set
        // explicitly because the alternative is inheriting PDO's default, which
        // is around a minute — long enough that a contended write looks like a
        // hung request rather than a busy database.
        $connection->exec('PRAGMA busy_timeout = ' . self::BUSY_TIMEOUT_MS);

        return $this->connection = $connection;
    }

    /**
     * @param list<mixed> $bindings
     */
    private function run(string $sql, array $bindings = []): \PDOStatement
    {
        $statement = $this->connection()->prepare($sql);

        foreach ($bindings as $index => $binding) {
            // Binding by type is not a nicety. PDO binds everything as a string
            // by default, which would store the integer id 1 as the text '1'
            // and quietly break both id generation and every lookup.
            $statement->bindValue($index + 1, $binding, $this->parameterType($binding));
        }

        $statement->execute();

        return $statement;
    }

    /**
     * SQLITE_CONSTRAINT is 19; PDO also reports it as SQLSTATE 23000. Either is
     * enough to tell "that id is taken" apart from "the disk is full".
     */
    private function isConstraintViolation(PDOException $e): bool
    {
        return ($e->errorInfo[1] ?? null) === 19 || $e->getCode() === '23000';
    }

    private function parameterType(mixed $value): int
    {
        return match (true) {
            is_int($value) => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            $value === null => PDO::PARAM_NULL,
            default => PDO::PARAM_STR,
        };
    }

    /**
     * @template T
     *
     * @param callable(): T $work
     *
     * @return T
     */
    private function transaction(callable $work): mixed
    {
        $connection = $this->connection();

        if ($connection->inTransaction()) {
            return $work();
        }

        // IMMEDIATE takes the write lock up front, so reading the highest id
        // and inserting past it cannot be interleaved by another writer.
        $connection->exec('BEGIN IMMEDIATE');

        try {
            $result = $work();
            $connection->exec('COMMIT');

            return $result;
        } catch (Throwable $e) {
            $connection->exec('ROLLBACK');

            throw $e;
        }
    }

    private function createTable(string $collection): void
    {
        // id is deliberately typeless: that is the one column kind SQLite will
        // not coerce, so an integer id stays an integer and a string id stays a
        // string. Declaring it INTEGER PRIMARY KEY would silently rewrite
        // 'usr_ab12' and declaring it TEXT would rewrite 1.
        $this->run("CREATE TABLE IF NOT EXISTS \"{$collection}\" (id PRIMARY KEY, data TEXT NOT NULL)");
    }

    private function tableExists(string $collection): bool
    {
        $found = $this->run(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ?",
            [$collection],
        )->fetchColumn();

        return $found !== false;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function encode(array $record): string
    {
        return $this->toJson($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(string $collection, string $data): array
    {
        try {
            return json_decode($data, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable $e) {
            throw new DataStorageException("A record in the collection '{$collection}' is not readable JSON.", $e);
        }
    }
}
