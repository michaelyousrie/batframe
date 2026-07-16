<?php

declare(strict_types=1);

namespace Batframe\DataStorage;

/**
 * Translates an {@see Is} into one driver's query language.
 *
 * **This is the seam that keeps drivers open.** `Is` describes intent and knows
 * no dialect; a driver that speaks a query language brings a compiler that
 * turns that intent into its own. Adding MySQL means writing a `MySqlStore` and
 * a `MySqlCompiler` — `Is` is not touched, and neither is any existing driver.
 *
 * A driver only needs a compiler if it pushes filtering down into a query
 * engine. {@see Json\JsonStore} has none: it filters in PHP with
 * `Is::matches()`, and so would a driver over an engine with no query language.
 *
 * A compiler owes its driver two things. It must produce a fragment that is
 * safe to combine with AND, OR and NOT, and it must reproduce the semantics
 * `Is::matches()` defines — the compiler is a translation, not a second
 * opinion. `tests/StoreContractTestCase.php` is where a disagreement surfaces.
 */
interface CriteriaCompiler
{
    /**
     * Compile a criterion against a field into a fragment plus its bindings,
     * in the order the fragment's placeholders appear.
     *
     * The compiler builds the whole fragment, field access included, so it is
     * free to drop the field entirely when the criterion does not need it — an
     * empty `Is::in([])` list has no matching rows and needs no field lookup to
     * say so.
     *
     * @return array{0: string, 1: list<mixed>}
     *
     * @throws DataStorageException if the operator is one this driver cannot express
     */
    public function compile(string $field, Is $is): array;
}
