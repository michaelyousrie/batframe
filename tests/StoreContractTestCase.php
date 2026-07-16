<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Is;
use Batframe\DataStorage\Store;
use PHPUnit\Framework\TestCase;

/**
 * Everything a Store owes its caller, in one place.
 *
 * **This is what makes "swappable" true rather than merely claimed.** Every
 * driver runs this identical suite, so a driver that behaves differently from
 * the others fails the build instead of surprising someone in production. A new
 * driver extends this case and inherits the whole contract.
 *
 * Nothing here may mention a specific driver. Driver-specific behaviour (how
 * bytes land on disk, what a connection is) belongs in that driver's own test.
 *
 * PHPUnit skips abstract classes, so this file defines tests without running
 * them; JsonStoreTest and SqliteStoreTest are what run them.
 */
abstract class StoreContractTestCase extends TestCase
{
    /** The moment the fake clock starts at, as the stores format it. */
    protected const T0 = '1970-01-12T13:46:40+00:00';

    protected Store $store;

    /** Mutable "now", so timestamps are deterministic without sleeping. */
    protected int $now = 1_000_000;

    /**
     * Build the driver under test, reading time from $this->now.
     */
    abstract protected function makeStore(): Store;

    protected function setUp(): void
    {
        $this->store = $this->makeStore();
    }

    /**
     * Four users worth having opinions about: two share an age (ties), one has
     * no age at all (a missing field is null), and the roles overlap.
     */
    protected function seed(): void
    {
        $this->store->insertMany('users', [
            ['name' => 'Michael', 'age' => 36, 'role' => 'admin'],
            ['name' => 'F0rty', 'age' => 17, 'role' => 'guest'],
            ['name' => 'Ada', 'age' => 36, 'role' => 'owner'],
            ['name' => 'Grace', 'role' => 'guest'],
        ]);
    }

    /**
     * @param list<array<string, mixed>> $records
     *
     * @return list<mixed>
     */
    protected function pluck(array $records, string $field): array
    {
        return array_map(static fn (array $record): mixed => $record[$field] ?? null, $records);
    }

    // ------------------------------------------------------------------
    // Identity
    // ------------------------------------------------------------------

    public function test_insert_returns_the_record_as_stored(): void
    {
        $user = $this->store->insert('users', ['name' => 'Michael']);

        $this->assertSame('Michael', $user['name']);
        $this->assertSame(1, $user['id']);
        $this->assertSame($user, $this->store->get('users', 1));
    }

    public function test_ids_auto_increment(): void
    {
        $this->assertSame(1, $this->store->insert('users', ['name' => 'a'])['id']);
        $this->assertSame(2, $this->store->insert('users', ['name' => 'b'])['id']);
        $this->assertSame(3, $this->store->insert('users', ['name' => 'c'])['id']);
    }

    public function test_ids_are_per_collection(): void
    {
        $this->store->insert('users', ['name' => 'a']);

        $this->assertSame(1, $this->store->insert('posts', ['title' => 'p'])['id']);
    }

    public function test_a_caller_supplied_id_wins(): void
    {
        $user = $this->store->insert('users', ['id' => 99, 'name' => 'Imported']);

        $this->assertSame(99, $user['id']);
        $this->assertSame('Imported', $this->store->get('users', 99)['name']);

        // The next auto id carries on from the highest, not from where it left off.
        $this->assertSame(100, $this->store->insert('users', ['name' => 'Next'])['id']);
    }

    public function test_a_caller_supplied_id_may_be_a_string(): void
    {
        $user = $this->store->insert('users', ['id' => 'usr_ab12', 'name' => 'Michael']);

        $this->assertSame('usr_ab12', $user['id']);
        $this->assertSame('Michael', $this->store->get('users', 'usr_ab12')['name']);
    }

    public function test_string_ids_do_not_disturb_auto_increment(): void
    {
        $this->store->insert('users', ['id' => 'usr_ab12', 'name' => 'a']);

        // Only integer ids count toward the next one.
        $this->assertSame(1, $this->store->insert('users', ['name' => 'b'])['id']);
    }

    public function test_an_int_id_and_a_string_id_are_different_records(): void
    {
        $this->store->insert('users', ['id' => 1, 'name' => 'int']);
        $this->store->insert('users', ['id' => '1', 'name' => 'string']);

        $this->assertSame('int', $this->store->get('users', 1)['name']);
        $this->assertSame('string', $this->store->get('users', '1')['name']);
    }

    public function test_a_duplicate_id_is_refused(): void
    {
        $this->store->insert('users', ['id' => 7, 'name' => 'first']);

        try {
            $this->store->insert('users', ['id' => 7, 'name' => 'second']);
            $this->fail('Expected DataStorageException');
        } catch (DataStorageException $e) {
            $this->assertSame(500, $e->getStatusCode());
        }

        // The first record is untouched.
        $this->assertSame('first', $this->store->get('users', 7)['name']);
    }

    public function test_a_record_that_cannot_be_stored_leaves_the_collection_intact(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);
        $this->store->insert('users', ['name' => 'Ada']);

        // Invalid UTF-8 — one paste from a Latin-1 source is enough. A write
        // that cannot complete must fail without taking the existing records
        // with it: a rejected insert is an error, not a reason to lose data.
        try {
            $this->store->insert('users', ['name' => "bad\xB1\x31utf8"]);
            $this->fail('Expected DataStorageException');
        } catch (DataStorageException $e) {
            $this->assertStringContainsString('JSON', $e->getMessage());
        }

        $this->assertSame(['Michael', 'Ada'], $this->pluck($this->store->all('users'), 'name'));
    }

    public function test_a_failed_update_leaves_the_collection_intact(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);

        try {
            $this->store->update('users', 1, ['name' => "bad\xB1\x31utf8"]);
            $this->fail('Expected DataStorageException');
        } catch (DataStorageException $e) {
            // The record is untouched, not half-written and not gone.
            $this->assertSame('Michael', $this->store->get('users', 1)['name']);
        }
    }

    public function test_insert_many_returns_every_record_as_stored(): void
    {
        $users = $this->store->insertMany('users', [
            ['name' => 'a'],
            ['name' => 'b'],
        ]);

        $this->assertCount(2, $users);
        $this->assertSame([1, 2], $this->pluck($users, 'id'));
        $this->assertSame(2, $this->store->count('users'));
    }

    // ------------------------------------------------------------------
    // Timestamps
    // ------------------------------------------------------------------

    public function test_insert_stamps_created_and_updated(): void
    {
        $user = $this->store->insert('users', ['name' => 'Michael']);

        $this->assertSame(self::T0, $user['created_at']);
        $this->assertSame(self::T0, $user['updated_at']);
    }

    public function test_caller_supplied_timestamps_win(): void
    {
        $user = $this->store->insert('users', [
            'name' => 'Imported',
            'created_at' => '2019-01-01T00:00:00+00:00',
        ]);

        $this->assertSame('2019-01-01T00:00:00+00:00', $user['created_at']);

        // The one that was not supplied is still filled in.
        $this->assertSame(self::T0, $user['updated_at']);
    }

    public function test_update_refreshes_updated_at_only(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);

        $this->now += 60;
        $user = $this->store->update('users', 1, ['name' => 'Mike']);

        $this->assertSame(self::T0, $user['created_at']);
        $this->assertSame('1970-01-12T13:47:40+00:00', $user['updated_at']);
    }

    public function test_an_update_may_override_updated_at(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);
        $this->now += 60;

        $user = $this->store->update('users', 1, ['updated_at' => '2019-01-01T00:00:00+00:00']);

        $this->assertSame('2019-01-01T00:00:00+00:00', $user['updated_at']);
    }

    // ------------------------------------------------------------------
    // Reading
    // ------------------------------------------------------------------

    public function test_get_returns_null_when_there_is_no_such_record(): void
    {
        $this->assertNull($this->store->get('users', 404));
    }

    public function test_all_is_insertion_order(): void
    {
        $this->seed();

        $this->assertSame(['Michael', 'F0rty', 'Ada', 'Grace'], $this->pluck($this->store->all('users'), 'name'));
    }

    public function test_an_unknown_collection_is_empty_rather_than_an_error(): void
    {
        $this->assertSame([], $this->store->all('nothing_here'));
        $this->assertSame([], $this->store->find('nothing_here'));
        $this->assertSame(0, $this->store->count('nothing_here'));
        $this->assertFalse($this->store->exists('nothing_here'));
        $this->assertNull($this->store->get('nothing_here', 1));
    }

    public function test_values_keep_their_types(): void
    {
        $this->store->insert('things', [
            'int' => 42,
            'float' => 1.5,
            'string' => 'x',
            'true' => true,
            'false' => false,
            'null' => null,
            'list' => [1, 2, 3],
            'map' => ['a' => 1],
        ]);

        $thing = $this->store->get('things', 1);

        $this->assertSame(42, $thing['int']);
        $this->assertSame(1.5, $thing['float']);
        $this->assertSame('x', $thing['string']);
        $this->assertTrue($thing['true']);
        $this->assertFalse($thing['false']);
        $this->assertNull($thing['null']);
        $this->assertSame([1, 2, 3], $thing['list']);
        $this->assertSame(['a' => 1], $thing['map']);
    }

    // ------------------------------------------------------------------
    // find()
    // ------------------------------------------------------------------

    public function test_find_with_no_criteria_is_everything(): void
    {
        $this->seed();

        $this->assertCount(4, $this->store->find('users'));
    }

    public function test_a_bare_value_means_equality(): void
    {
        $this->seed();

        $found = $this->store->find('users', ['name' => 'Michael']);

        $this->assertCount(1, $found);
        $this->assertSame('Michael', $found[0]['name']);

        // Spelled out, it is the same query.
        $this->assertSame($found, $this->store->find('users', ['name' => Is::equals('Michael')]));
    }

    public function test_entries_are_anded_together(): void
    {
        $this->seed();

        $this->assertSame(
            ['Michael'],
            $this->pluck($this->store->find('users', ['age' => 36, 'role' => 'admin']), 'name'),
        );

        $this->assertSame([], $this->store->find('users', ['age' => 36, 'role' => 'guest']));
    }

    public function test_or_adds_a_second_group(): void
    {
        $this->seed();

        // (name is Michael) OR (name is F0rty)
        $found = $this->store->find('users', ['name' => 'Michael'], or: ['name' => 'F0rty']);

        $this->assertSame(['Michael', 'F0rty'], $this->pluck($found, 'name'));
    }

    public function test_each_or_group_is_anded_within_itself(): void
    {
        $this->seed();

        // (age 36 AND role admin) OR (role guest AND age 17)
        $found = $this->store->find(
            'users',
            ['age' => 36, 'role' => 'admin'],
            or: ['role' => 'guest', 'age' => 17],
        );

        $this->assertSame(['Michael', 'F0rty'], $this->pluck($found, 'name'));
    }

    public function test_a_missing_field_reads_as_null(): void
    {
        $this->seed();

        // Grace has no age at all.
        $this->assertSame(['Grace'], $this->pluck($this->store->find('users', ['age' => Is::null()]), 'name'));
        $this->assertSame(['Grace'], $this->pluck($this->store->find('users', ['age' => null]), 'name'));

        $this->assertSame(
            ['Michael', 'F0rty', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => Is::notNull()]), 'name'),
        );
    }

    public function test_find_one_returns_the_first_match_or_null(): void
    {
        $this->seed();

        $this->assertSame('Michael', $this->store->findOne('users', ['age' => 36])['name']);
        $this->assertNull($this->store->findOne('users', ['name' => 'Nobody']));
    }

    // ------------------------------------------------------------------
    // find() with criteria — every operator, through the driver
    // ------------------------------------------------------------------

    public function test_ordering_criteria(): void
    {
        $this->seed();

        $this->assertSame(
            ['Michael', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => Is::greaterThan(17)]), 'name'),
        );

        $this->assertSame(
            ['Michael', 'F0rty', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => Is::greaterOrEqual(17)]), 'name'),
        );

        $this->assertSame(
            ['F0rty'],
            $this->pluck($this->store->find('users', ['age' => Is::lessThan(36)]), 'name'),
        );

        $this->assertSame(
            ['Michael', 'F0rty', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => Is::lessOrEqual(36)]), 'name'),
        );

        $this->assertSame(
            ['Michael', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => Is::between(18, 40)]), 'name'),
        );
    }

    public function test_an_ordering_criterion_never_selects_a_missing_field(): void
    {
        $this->seed();

        // Grace has no age. She is not younger than 100, she is unknown.
        $this->assertNotContains('Grace', $this->pluck($this->store->find('users', ['age' => Is::lessThan(100)]), 'name'));
    }

    public function test_float_criteria(): void
    {
        // Prices, scores, ratings. A driver that cannot compare a float is
        // useless for most of what people actually store, and PDO has no float
        // parameter type to fall back on — so this is where a driver quietly
        // returns nothing at all.
        $this->store->insertMany('products', [
            ['name' => 'cheap', 'price' => 5.5],
            ['name' => 'dear', 'price' => 9.99],
        ]);

        $this->assertSame(['dear'], $this->pluck($this->store->find('products', ['price' => 9.99]), 'name'));
        $this->assertSame(['cheap'], $this->pluck($this->store->find('products', ['price' => Is::notEquals(9.99)]), 'name'));
        $this->assertSame(['dear'], $this->pluck($this->store->find('products', ['price' => Is::greaterThan(6.0)]), 'name'));
        $this->assertSame(['cheap'], $this->pluck($this->store->find('products', ['price' => Is::lessThan(6.0)]), 'name'));
        $this->assertSame(['dear'], $this->pluck($this->store->find('products', ['price' => Is::in([9.99])]), 'name'));
        $this->assertSame(['cheap'], $this->pluck($this->store->find('products', ['price' => Is::between(5.0, 6.0)]), 'name'));
        $this->assertSame(['cheap'], $this->pluck($this->store->find('products', ['price' => Is::not(Is::greaterThan(6.0))]), 'name'));

        $this->assertSame(
            ['cheap', 'dear'],
            $this->pluck($this->store->find('products', orderBy: 'price'), 'name'),
        );
    }

    public function test_an_integer_criterion_matches_a_whole_float_and_vice_versa(): void
    {
        // 10 and 10.0 are the same number, and json_decode hands back whichever
        // the JSON text implied. Anything else would make a driver's storage
        // format leak into which records a query finds.
        $this->store->insert('products', ['name' => 'ten', 'price' => 10.0]);

        $this->assertSame(['ten'], $this->pluck($this->store->find('products', ['price' => Is::greaterOrEqual(10)]), 'name'));
        $this->assertSame(['ten'], $this->pluck($this->store->find('products', ['price' => Is::between(9, 11)]), 'name'));
        $this->assertSame(['ten'], $this->pluck($this->store->find('products', ['price' => Is::lessThan(10.5)]), 'name'));
    }

    public function test_membership_criteria(): void
    {
        $this->seed();

        $this->assertSame(
            ['Michael', 'Ada'],
            $this->pluck($this->store->find('users', ['role' => Is::in(['admin', 'owner'])]), 'name'),
        );

        $this->assertSame(
            ['F0rty', 'Grace'],
            $this->pluck($this->store->find('users', ['role' => Is::notIn(['admin', 'owner'])]), 'name'),
        );

        $this->assertSame([], $this->store->find('users', ['role' => Is::in([])]));
        $this->assertCount(4, $this->store->find('users', ['role' => Is::notIn([])]));
    }

    public function test_equality_does_not_confuse_a_boolean_with_a_number(): void
    {
        $this->store->insertMany('flags', [
            ['name' => 'yes', 'v' => true],
            ['name' => 'one', 'v' => 1],
        ]);

        // true and 1 are different values, and a driver that stores booleans as
        // integers must not let its storage format decide what a query means.
        $this->assertSame(['one'], $this->pluck($this->store->find('flags', ['v' => 1]), 'name'));
        $this->assertSame(['yes'], $this->pluck($this->store->find('flags', ['v' => true]), 'name'));
        $this->assertSame(['yes'], $this->pluck($this->store->find('flags', ['v' => Is::notEquals(1)]), 'name'));
    }

    public function test_criteria_never_reach_inside_a_structured_field(): void
    {
        $this->store->insertMany('posts', [
            ['name' => 'listy', 'tags' => ['php', 'web']],
            ['name' => 'texty', 'tags' => 'php'],
        ]);

        // 'listy' stores a list. A driver that keeps records as JSON must not
        // let a text search match the *serialisation* of that list — only
        // 'texty' has a tags field that is a string containing "php".
        $this->assertSame(['texty'], $this->pluck($this->store->find('posts', ['tags' => Is::like('%php%')]), 'name'));
        $this->assertSame(['texty'], $this->pluck($this->store->find('posts', ['tags' => Is::contains('php')]), 'name'));
        $this->assertSame(['texty'], $this->pluck($this->store->find('posts', ['tags' => Is::startsWith('php')]), 'name'));
        $this->assertSame(['texty'], $this->pluck($this->store->find('posts', ['tags' => Is::endsWith('php')]), 'name'));
    }

    public function test_a_criterion_on_a_non_scalar_value_is_refused(): void
    {
        $this->store->insert('posts', ['tags' => ['php', 'web']]);

        // Comparing whole structures is out of scope. Saying so is better than
        // one driver comparing arrays in PHP while another compares their JSON
        // text — the answers would differ and nobody would be told.
        foreach ([['php', 'web'], ['a' => 1]] as $structure) {
            try {
                $this->store->find('posts', ['tags' => $structure]);
                $this->fail('Expected DataStorageException');
            } catch (DataStorageException $e) {
                $this->assertStringContainsString('scalar', $e->getMessage());
            }
        }
    }

    public function test_a_field_name_with_a_dot_is_a_field_name(): void
    {
        // Criteria address top-level fields. A dot is part of the name, not a
        // path into something nested — one driver must not read it as a path
        // expression while the other treats it as a literal key.
        $this->store->insert('metrics', ['a.b' => 'dotted', 'name' => 'has-dot']);

        $this->assertSame(['has-dot'], $this->pluck($this->store->find('metrics', ['a.b' => 'dotted']), 'name'));
        $this->assertSame([], $this->store->find('metrics', ['a' => Is::notNull()]));
        $this->assertSame(['has-dot'], $this->pluck($this->store->find('metrics', orderBy: 'a.b'), 'name'));
    }

    public function test_null_inside_a_membership_list(): void
    {
        $this->seed();

        // Grace has no age, so null is what a membership test sees for her.
        $this->assertSame(
            ['F0rty', 'Grace'],
            $this->pluck($this->store->find('users', ['age' => Is::in([17, null])]), 'name'),
        );

        $this->assertSame(['Grace'], $this->pluck($this->store->find('users', ['age' => Is::in([null])]), 'name'));

        $this->assertSame(
            ['Michael', 'F0rty', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => Is::notIn([null])]), 'name'),
        );
    }

    public function test_text_search_criteria(): void
    {
        $this->store->insertMany('users', [
            ['name' => 'testingName', 'email' => 'test@example.com'],
            ['name' => 'other', 'email' => 'nope@elsewhere.org'],
        ]);

        $this->assertSame(['testingName'], $this->pluck($this->store->find('users', ['name' => Is::contains('esting')]), 'name'));
        $this->assertSame(['testingName'], $this->pluck($this->store->find('users', ['name' => Is::startsWith('testing')]), 'name'));
        $this->assertSame(['testingName'], $this->pluck($this->store->find('users', ['name' => Is::endsWith('Name')]), 'name'));
        $this->assertSame(['testingName'], $this->pluck($this->store->find('users', ['email' => Is::endsWith('@example.com')]), 'name'));

        // Case-insensitive, like the rest of the text criteria.
        $this->assertSame(['testingName'], $this->pluck($this->store->find('users', ['name' => Is::contains('ESTING')]), 'name'));

        $this->assertSame([], $this->store->find('users', ['name' => Is::contains('zzz')]));
        $this->assertSame([], $this->store->find('users', ['name' => Is::startsWith('Name')]));
        $this->assertSame([], $this->store->find('users', ['name' => Is::endsWith('testing')]));

        // A missing field is null, and null contains nothing.
        $this->assertSame([], $this->store->find('users', ['absent' => Is::contains('x')]));
    }

    public function test_text_search_takes_no_wildcards(): void
    {
        // The whole point of these is that there is no pattern syntax to get
        // wrong — so a % or _ in what you are searching for is just a character.
        $this->store->insertMany('deals', [
            ['label' => '50% off'],
            ['label' => '50 off'],
            ['label' => 'a_b'],
            ['label' => 'axb'],
        ]);

        $this->assertSame(['50% off'], $this->pluck($this->store->find('deals', ['label' => Is::contains('50%')]), 'label'));
        $this->assertSame(['50% off'], $this->pluck($this->store->find('deals', ['label' => Is::startsWith('50%')]), 'label'));
        $this->assertSame(['a_b'], $this->pluck($this->store->find('deals', ['label' => Is::contains('a_b')]), 'label'));
        $this->assertSame(['a_b'], $this->pluck($this->store->find('deals', ['label' => Is::endsWith('a_b')]), 'label'));
    }

    public function test_text_search_reads_a_boolean_as_one_or_zero(): void
    {
        // Records are schemaless, so a text search will meet non-text values.
        // A driver storing booleans as integers must not be the reason two
        // drivers disagree about what searching them finds.
        $this->store->insertMany('rows', [
            ['label' => 'yes', 'v' => true],
            ['label' => 'no', 'v' => false],
            ['label' => 'num', 'v' => 42],
        ]);

        $this->assertSame(['yes'], $this->pluck($this->store->find('rows', ['v' => Is::contains('1')]), 'label'));
        $this->assertSame(['no'], $this->pluck($this->store->find('rows', ['v' => Is::contains('0')]), 'label'));
        $this->assertSame(['num'], $this->pluck($this->store->find('rows', ['v' => Is::contains('42')]), 'label'));
    }

    public function test_like_criteria(): void
    {
        $this->seed();

        $this->assertSame(
            ['Michael'],
            $this->pluck($this->store->find('users', ['name' => Is::like('mich%')]), 'name'),
        );

        $this->assertSame(
            ['Ada'],
            $this->pluck($this->store->find('users', ['name' => Is::like('_da')]), 'name'),
        );
    }

    public function test_negation_criteria(): void
    {
        $this->seed();

        $this->assertSame(
            ['F0rty', 'Ada', 'Grace'],
            $this->pluck($this->store->find('users', ['name' => Is::not('Michael')]), 'name'),
        );

        $this->assertSame(
            ['F0rty', 'Grace'],
            $this->pluck($this->store->find('users', ['role' => Is::not(Is::in(['admin', 'owner']))]), 'name'),
        );

        // !(age > 18) includes the record with no age at all.
        $this->assertSame(
            ['F0rty', 'Grace'],
            $this->pluck($this->store->find('users', ['age' => Is::not(Is::greaterThan(18))]), 'name'),
        );
    }

    // ------------------------------------------------------------------
    // findNot()
    // ------------------------------------------------------------------

    public function test_find_not_negates_the_matcher(): void
    {
        $this->seed();

        $this->assertSame(
            ['F0rty', 'Ada', 'Grace'],
            $this->pluck($this->store->findNot('users', ['name' => 'Michael']), 'name'),
        );
    }

    public function test_find_not_negates_the_or_group_too(): void
    {
        $this->seed();

        // Neither Michael nor F0rty.
        $this->assertSame(
            ['Ada', 'Grace'],
            $this->pluck($this->store->findNot('users', ['name' => 'Michael'], or: ['name' => 'F0rty']), 'name'),
        );
    }

    public function test_find_not_with_no_criteria_is_nothing(): void
    {
        $this->seed();

        // find() with no criteria is everything, so its negation is nothing.
        $this->assertSame([], $this->store->findNot('users'));
    }

    public function test_find_not_takes_ordering_and_paging_too(): void
    {
        $this->seed();

        // Everyone but Michael, by name descending, first two.
        $this->assertSame(
            ['Grace', 'F0rty'],
            $this->pluck($this->store->findNot('users', ['name' => 'Michael'], orderBy: 'name', desc: true, limit: 2), 'name'),
        );
    }

    // ------------------------------------------------------------------
    // Ordering and paging
    // ------------------------------------------------------------------

    public function test_order_by(): void
    {
        $this->seed();

        $this->assertSame(
            ['Ada', 'F0rty', 'Grace', 'Michael'],
            $this->pluck($this->store->find('users', orderBy: 'name'), 'name'),
        );

        $this->assertSame(
            ['Michael', 'Grace', 'F0rty', 'Ada'],
            $this->pluck($this->store->find('users', orderBy: 'name', desc: true), 'name'),
        );
    }

    public function test_ties_keep_insertion_order(): void
    {
        $this->seed();

        // Michael and Ada are both 36, and Michael was inserted first. An
        // unstable sort would flip them, and only on some drivers.
        $this->assertSame(
            ['Michael', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => 36], orderBy: 'age'), 'name'),
        );

        $this->assertSame(
            ['Michael', 'Ada'],
            $this->pluck($this->store->find('users', ['age' => 36], orderBy: 'age', desc: true), 'name'),
        );
    }

    public function test_ordering_puts_missing_fields_first_ascending_and_last_descending(): void
    {
        $this->seed();

        $this->assertSame(
            ['Grace', 'F0rty', 'Michael', 'Ada'],
            $this->pluck($this->store->find('users', orderBy: 'age'), 'name'),
        );

        $this->assertSame(
            ['Michael', 'Ada', 'F0rty', 'Grace'],
            $this->pluck($this->store->find('users', orderBy: 'age', desc: true), 'name'),
        );
    }

    public function test_a_missing_field_sorts_before_negative_numbers(): void
    {
        // Null is not zero. PHP alone would sort null after -5; the contract
        // says a missing value sorts first, and every driver must agree.
        $this->store->insertMany('scores', [
            ['label' => 'negative', 'value' => -5],
            ['label' => 'missing'],
            ['label' => 'positive', 'value' => 3],
        ]);

        $this->assertSame(
            ['missing', 'negative', 'positive'],
            $this->pluck($this->store->find('scores', orderBy: 'value'), 'label'),
        );
    }

    public function test_ordering_across_mixed_types(): void
    {
        // Collections are schemaless, so one field holding several types is
        // allowed and therefore has to sort the same way everywhere. The order
        // is: nothing first, then numbers by value (a boolean is 1 or 0), then
        // text. PHP's own <=> would not do this on its own — it reads '10' as
        // ten next to '9', and calls null the larger of null and -5.
        $this->store->insertMany('mixed', [
            ['label' => 'text', 'v' => 'abc'],
            ['label' => 'ten', 'v' => 10],
            ['label' => 'true', 'v' => true],
            ['label' => 'numeric string', 'v' => '9'],
            ['label' => 'missing'],
            ['label' => 'float', 'v' => 2.5],
            ['label' => 'false', 'v' => false],
        ]);

        $ascending = ['missing', 'false', 'true', 'float', 'ten', 'numeric string', 'text'];

        $this->assertSame($ascending, $this->pluck($this->store->find('mixed', orderBy: 'v'), 'label'));

        $this->assertSame(
            array_reverse($ascending),
            $this->pluck($this->store->find('mixed', orderBy: 'v', desc: true), 'label'),
        );
    }

    public function test_text_ordering_is_by_character_not_by_value(): void
    {
        // '10' sorts before '9' because they are text. PHP compares two numeric
        // strings as numbers, which would put them the other way round.
        $this->store->insertMany('tags', [
            ['v' => '9'],
            ['v' => '10'],
            ['v' => '100'],
        ]);

        $this->assertSame(['10', '100', '9'], $this->pluck($this->store->find('tags', orderBy: 'v'), 'v'));
    }

    public function test_limit_and_offset(): void
    {
        $this->seed();

        $this->assertSame(
            ['Ada', 'F0rty'],
            $this->pluck($this->store->find('users', orderBy: 'name', limit: 2), 'name'),
        );

        $this->assertSame(
            ['Grace', 'Michael'],
            $this->pluck($this->store->find('users', orderBy: 'name', limit: 2, offset: 2), 'name'),
        );

        // Past the end is empty, not an error.
        $this->assertSame([], $this->store->find('users', orderBy: 'name', limit: 2, offset: 99));
    }

    public function test_offset_without_limit(): void
    {
        $this->seed();

        $this->assertSame(
            ['Grace', 'Michael'],
            $this->pluck($this->store->find('users', orderBy: 'name', offset: 2), 'name'),
        );
    }

    public function test_ordering_and_paging_apply_after_filtering(): void
    {
        $this->seed();

        $this->assertSame(
            ['Michael'],
            $this->pluck($this->store->find('users', ['role' => Is::in(['admin', 'owner'])], orderBy: 'name', desc: true, limit: 1), 'name'),
        );
    }

    // ------------------------------------------------------------------
    // Updating
    // ------------------------------------------------------------------

    public function test_update_merges_rather_than_replaces(): void
    {
        $this->store->insert('users', ['name' => 'Michael', 'age' => 36, 'role' => 'admin']);

        $user = $this->store->update('users', 1, ['age' => 37]);

        $this->assertSame(37, $user['age']);
        $this->assertSame('Michael', $user['name']);
        $this->assertSame('admin', $user['role']);
        $this->assertSame($user, $this->store->get('users', 1));
    }

    public function test_update_can_add_a_field(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);

        $this->assertSame('bat', $this->store->update('users', 1, ['tag' => 'bat'])['tag']);
    }

    public function test_update_cannot_change_the_id(): void
    {
        $this->store->insert('users', ['name' => 'Michael']);

        $this->store->update('users', 1, ['id' => 99]);

        // The record is still where it was.
        $this->assertSame('Michael', $this->store->get('users', 1)['name']);
        $this->assertNull($this->store->get('users', 99));
    }

    public function test_update_returns_null_when_there_is_no_such_record(): void
    {
        $this->assertNull($this->store->update('users', 404, ['name' => 'x']));
    }

    public function test_update_where_returns_the_number_updated(): void
    {
        $this->seed();

        $this->assertSame(2, $this->store->updateWhere('users', ['age' => 36], ['role' => 'staff']));

        $this->assertSame(
            ['Michael', 'Ada'],
            $this->pluck($this->store->find('users', ['role' => 'staff']), 'name'),
        );
    }

    public function test_update_where_takes_an_or_group(): void
    {
        $this->seed();

        $updated = $this->store->updateWhere('users', ['name' => 'Michael'], ['role' => 'staff'], or: ['name' => 'Grace']);

        $this->assertSame(2, $updated);
        $this->assertSame(['Michael', 'Grace'], $this->pluck($this->store->find('users', ['role' => 'staff']), 'name'));
    }

    public function test_update_where_matching_nothing_is_zero(): void
    {
        $this->seed();

        $this->assertSame(0, $this->store->updateWhere('users', ['name' => 'Nobody'], ['role' => 'staff']));
    }

    // ------------------------------------------------------------------
    // Deleting
    // ------------------------------------------------------------------

    public function test_delete(): void
    {
        $this->seed();

        $this->assertTrue($this->store->delete('users', 1));
        $this->assertNull($this->store->get('users', 1));
        $this->assertSame(3, $this->store->count('users'));
    }

    public function test_delete_returns_false_when_there_is_no_such_record(): void
    {
        $this->assertFalse($this->store->delete('users', 404));
    }

    public function test_deleting_from_the_middle_does_not_shift_ids(): void
    {
        $this->seed();
        $this->store->delete('users', 2);

        $this->assertSame([1, 3, 4], $this->pluck($this->store->all('users'), 'id'));

        // Next id follows the highest, not the count.
        $this->assertSame(5, $this->store->insert('users', ['name' => 'New'])['id']);
    }

    public function test_deleting_the_highest_id_frees_it_for_reuse(): void
    {
        $this->seed();
        $this->store->delete('users', 4);

        // The next id is the highest that *currently exists* plus one, so the
        // id of a deleted last record comes back. Neither driver keeps a
        // counter: the JSON file stays a plain readable array of records, and
        // SQLite computes the same rule rather than using AUTOINCREMENT. Supply
        // your own ids if you need them never to repeat.
        $this->assertSame(4, $this->store->insert('users', ['name' => 'New'])['id']);
    }

    public function test_delete_where_returns_the_number_deleted(): void
    {
        $this->seed();

        $this->assertSame(2, $this->store->deleteWhere('users', ['role' => 'guest']));
        $this->assertSame(['Michael', 'Ada'], $this->pluck($this->store->all('users'), 'name'));
    }

    public function test_delete_where_takes_an_or_group(): void
    {
        $this->seed();

        $this->assertSame(2, $this->store->deleteWhere('users', ['name' => 'Michael'], or: ['name' => 'Ada']));
        $this->assertSame(['F0rty', 'Grace'], $this->pluck($this->store->all('users'), 'name'));
    }

    public function test_truncate_empties_the_collection(): void
    {
        $this->seed();

        $this->store->truncate('users');

        $this->assertSame([], $this->store->all('users'));
        $this->assertSame(0, $this->store->count('users'));

        // And ids start over.
        $this->assertSame(1, $this->store->insert('users', ['name' => 'Fresh'])['id']);
    }

    // ------------------------------------------------------------------
    // Counting
    // ------------------------------------------------------------------

    public function test_count(): void
    {
        $this->seed();

        $this->assertSame(4, $this->store->count('users'));
        $this->assertSame(2, $this->store->count('users', ['age' => 36]));
        $this->assertSame(2, $this->store->count('users', ['name' => 'Michael'], or: ['name' => 'Ada']));
        $this->assertSame(0, $this->store->count('users', ['name' => 'Nobody']));
    }

    public function test_exists(): void
    {
        $this->seed();

        $this->assertTrue($this->store->exists('users'));
        $this->assertTrue($this->store->exists('users', ['name' => 'Michael']));
        $this->assertFalse($this->store->exists('users', ['name' => 'Nobody']));
        $this->assertTrue($this->store->exists('users', ['name' => 'Nobody'], or: ['name' => 'Ada']));
    }

    // ------------------------------------------------------------------
    // Collections
    // ------------------------------------------------------------------

    public function test_a_mutation_that_changes_nothing_creates_no_collection(): void
    {
        // Asking a store to change something that is not there is a no-op, not
        // a reason to bring a collection into existence. An admin screen built
        // on collections() would otherwise list ghosts on one driver and not
        // the other.
        $this->assertFalse($this->store->delete('ghosts', 1));
        $this->assertSame([], $this->store->collections(), 'delete() invented a collection');

        $this->assertNull($this->store->update('ghosts', 1, ['x' => 1]));
        $this->assertSame([], $this->store->collections(), 'update() invented a collection');

        $this->assertSame(0, $this->store->updateWhere('ghosts', ['x' => 1], ['y' => 2]));
        $this->assertSame([], $this->store->collections(), 'updateWhere() invented a collection');

        $this->assertSame(0, $this->store->deleteWhere('ghosts', ['x' => 1]));
        $this->assertSame([], $this->store->collections(), 'deleteWhere() invented a collection');

        $this->store->truncate('ghosts');
        $this->assertSame([], $this->store->collections());
    }

    public function test_collections_lists_what_holds_data(): void
    {
        $this->assertSame([], $this->store->collections());

        $this->store->insert('users', ['name' => 'a']);
        $this->store->insert('posts', ['title' => 'p']);

        $collections = $this->store->collections();
        sort($collections);

        $this->assertSame(['posts', 'users'], $collections);
    }

    // ------------------------------------------------------------------
    // Errors
    // ------------------------------------------------------------------

    public function test_an_illegal_collection_name_is_refused(): void
    {
        // The name reaches a filename on one driver and an SQL identifier on
        // another, so it is validated the same way everywhere rather than
        // trusted to whatever the driver happens to do with it.
        foreach (['users; DROP TABLE users', '../etc/passwd', 'users.json', '', 'a b'] as $name) {
            try {
                $this->store->insert($name, ['x' => 1]);
                $this->fail("Expected DataStorageException for collection name '{$name}'");
            } catch (DataStorageException $e) {
                $this->assertStringContainsString('collection name', $e->getMessage());
            }
        }
    }

    public function test_a_legal_collection_name_is_accepted(): void
    {
        foreach (['users', 'user_profiles', 'Users2'] as $name) {
            $this->assertSame(1, $this->store->insert($name, ['x' => 1])['id']);
        }
    }
}
