# Batframe: the contract

How Batframe behaves. This file ships inside the Composer package, and both the framework repo
and every application built on it import it, so it is the single description of the framework's
public behaviour.

**Keep it free of anything that is only true in one place.** No project layout, no build or test
commands, no internals: those live in the importing CLAUDE.md. If you change how the framework
behaves, this file is part of that change.

## The naming convention

You extend the `Batframe` base class, and **every public method whose name starts with an HTTP
verb becomes an endpoint, with its route inferred from the method name**. There is no route
file, no route table, no config to author. If you go looking for one, re-read this section.

A method is a route only if it starts with `get`, `post`, `put`, `patch`, `delete`, `head` or
`options`, or is exactly `index` (which is `GET /`).

- The remaining PascalCase words become lowercased, `/`-separated segments. **There is no
  pluralization.** The path is literal: `getUser` is `/user`, `getUsers` is `/users`.
- Typed scalar parameters become `{placeholder}` segments in declared order, appended after the
  segments derived from the name. `int` and `float` also constrain the match, so `/user/{id}`
  with `int $id` will not match `/user/abc`; that is a 404, not a 500.
- A `Request`-typed parameter is injected rather than routed, and may sit in any position.
- **A public method without a verb prefix is not a route.** That is how you write helpers: they
  are ordinary public methods, and the router ignores them.
- **Trait methods are routed.** Group related endpoints into a trait and `use` it. A trait
  method reports the *using* class as its declaring class, so it resolves exactly as if it had
  been written inline.

```php
public function index(): Response                     // GET /
public function getUsers(): array                     // GET /users
public function getUser(int $id): array               // GET /user/{id}
public function getUser(int $id, string $name): array // GET /user/{id}/{name}
public function postUsers(Request $request): Response // POST /users
private function formatName(string $n): string        // not a route
```

## What a handler returns

Return whatever is natural and Batframe normalizes it:

| Return | Becomes |
| --- | --- |
| `Response` | itself |
| `array`, scalar, `JsonSerializable` | JSON |
| `string` | HTML |
| `null` | 204 No Content |

## Errors

`HttpException` carries a status and headers; anything else is a 500. Rendering is content
negotiated on `Request::wantsJson()`, and when `debug` is on it includes the exception class,
file and trace. `abort(404, '...')` throws one for you; its return type is `never`.

A path that matches under a different verb is a **405** with an `Allow` header, not a 404.

## Static pages

Any file in the pages directory is served automatically, with no handler. `about.blade.php`
answers `/about`, and nested directories work (`docs/intro.blade.php` answers `/docs/intro`).
`.blade.php` is checked before `.html`. The root `/` maps to `index`, but a nested directory has
no index default.

Two things to know:

- Pages are a **fallback**, tried only when no route claims the path, and after the 405 check.
  A route on the same path makes the page unreachable.
- The page fallback **never looks at the verb**, so a page answers any method.

Blade is configured with both the views and pages directories as roots, so a page can `@extends`
a layout that lives under views.

## Input

```php
request('name')                  // input, whichever way it arrived
request()->input('name', 'ada')  // same, with a default
request()->query('page')         // query string only
request()->post('name')          // form body only
request()->json('id')            // decoded JSON body
request()->header('X-Token')
request()->wantsJson()           // content negotiation
```

## Validation

Single-value, and **type-safe by design: there are no magic-string rule names.** Build rules
from `Rule` factories:

```php
use Batframe\Validation\Rule;

validate($email, [Rule::required(), Rule::email()]);           // true, or throws (422)
request()->validate('name', [Rule::required(), Rule::max(50)]);
validateMany([                                                  // runs all, aggregates failures
    $email => [Rule::required(), Rule::email()],
    $age   => [Rule::integer(), Rule::between(18, 99)],
]);
```

Rules: `required`, `nullable`, `string`, `integer`, `boolean`, `numeric`, `alphaNum`, `alpha`,
`email`, `url`, `min`, `max`, `between`, `in`, `regex`.

A failure throws `ValidationException`, which is rendered as a 422 with an `errors` key. Do not
catch it just to re-throw an error response; that is already the behaviour.

Three sharp edges:

- **Size rules measure by the value's own type.** A string is measured by character length, an
  array by count, an `int`/`float` by numeric value. So a numeric *string* is measured by its
  length: `'12345'` passes `Rule::between(1, 5)` because it is five characters. Cast first when
  you mean the value: `validate((int) $v, [Rule::between(1, 5)])`.
- Evaluation order: `nullable` short-circuits a `null` to valid, then `required` fails on
  "empty" (`null`, `''` or `[]`), then the remaining rules in the order you listed them.
- `validateMany()` keys by the *value*, so PHP's array-key coercion applies. Failure messages
  live only on `ValidationException::errors()`.

`confirmed` and other cross-field rules are deliberately out of scope: a single value has no
siblings.

## Data storage

Data goes in **collections**, and a collection is **schemaless: there is nothing to declare, no
migration to run, and no model class to write.** A record is an array. If you go looking for a
schema file, re-read this line.

```php
db('users')->insert(['name' => 'Michael']);
// ['id' => 1, 'name' => 'Michael',
//  'created_at' => '2026-07-16T10:00:00+00:00', 'updated_at' => '2026-07-16T10:00:00+00:00']

db('users')->get(1);                             // by id, or null
db('users')->all();                              // everything, in insertion order
db('users')->find(['name' => 'Michael']);        // a list of matching records
db('users')->findOne(['name' => 'Michael']);     // the first match, or null
db('users')->update(1, ['name' => 'Mike']);      // merges; returns the record, or null
db('users')->delete(1);                          // bool
db('users')->count(['role' => 'admin']);
db('users')->exists(['role' => 'admin']);
```

Which driver answers is configuration, never code: `DB_DRIVER=json` (the default, one readable
file per collection under `storage/database/`) or `DB_DRIVER=sqlite`. Both behave identically —
that is enforced by one shared test suite, not merely intended — so **swapping is an `.env`
change and nothing else.** Reach for `sqlite` when you want real transactions and concurrent
writers; stay on `json` when you want to open the file and read it.

### Filtering

A bare value means equality. Anything richer is an `Is`, built from a factory, so there are no
magic-string operators here either:

```php
use Batframe\DataStorage\Is;

db('users')->find([
    'name' => 'Michael',                 // equality; same as Is::equals('Michael')
    'age'  => Is::greaterThan(18),
    'role' => Is::in(['admin', 'owner']),
    'bio'  => Is::contains('php'),       // also startsWith / endsWith
]);

db('users')->find(['name' => 'Michael'], or: ['name' => 'F0rty']);   // either one
db('users')->findNot(['name' => 'Michael']);                         // everyone else

db('users')->find(
    ['age' => Is::greaterThan(18)],
    orderBy: 'name', desc: true, limit: 10, offset: 20,
);
```

`Is::` factories: `equals`, `notEquals`, `not`, `greaterThan`, `greaterOrEqual`, `lessThan`,
`lessOrEqual`, `between`, `in`, `notIn`, `contains`, `startsWith`, `endsWith`, `like`, `null`,
`notNull`. `Is::not()` takes a value or another criterion, so `Is::not(Is::contains('php'))`
composes.

**Searching text: reach for `contains`, `startsWith` or `endsWith`.** They are case-insensitive
and have no pattern syntax, so a `%` or `_` in what you are looking for is just a character
(`Is::contains('50%')` finds "50% off" and not "50 off"). `Is::like()` is there for when you
genuinely want a pattern — and it is SQL LIKE, so **a pattern with no wildcard is an exact
match**: `Is::like('testing')` does *not* find "testingName", it finds "testing". That is
`Is::like('testing%')`, or better, `Is::startsWith('testing')`.

`updateWhere()` and `deleteWhere()` take the same criteria and return how many records they
touched.

### Sharp edges

- **A missing field is null, and null never satisfies an ordering comparison.** A record with no
  `age` is not caught by `Is::lessThan(100)` — it is unknown, not small. It *is* caught by
  `Is::null()` and by `Is::not(Is::greaterThan(18))`.
- **`or:` is one level.** The matcher is `(all of $where) OR (all of $or)`. There is no nesting;
  if you need it, you have outgrown this layer.
- **`findNot()` negates the whole matcher**, `or:` included: `findNot($a, or: $b)` is neither.
- **Comparisons are strict.** `Is::equals(18)` does not match `'18'` or `18.0`, `Is::equals(1)`
  does not match `true`, and the id `1` is a different record from the id `'1'`.
- **Criteria compare scalars.** A record may hold arrays and objects, but comparing a whole
  structure is refused — `find(['tags' => ['a', 'b']])` throws. Compare a field, not a shape.
  Criteria address top-level fields, and a `.` in a field name is part of the name.
- **Anything you supply wins.** Pass your own `id`, `created_at` or `updated_at` and it is kept
  verbatim. A duplicate id is refused.
- **Ids are `max(id) + 1`,** counting only integer ids, and nothing is persisted — so deleting
  the last record frees its id for reuse. Supply your own ids if they must never repeat.
- **`update()` merges and cannot move a record.** An `id` in `$values` is ignored.
- **`Is::like()` anchors; the text criteria do not.** `like('testing')` matches only "testing".
  Use `contains`/`startsWith`/`endsWith` unless you actually want wildcards.
- **Ordering is: nothing, then numbers, then text.** A missing field sorts first ascending and
  last descending; a boolean sorts among the numbers as 1 or 0; text sorts by character, so `'10'`
  comes before `'9'`. Ties keep insertion order.
- **Text criteria read a boolean as `'1'`/`'0'`** and a number as its digits, so
  `Is::contains('1')` finds a record whose flag is `true`.
- The JSON driver **rewrites the whole file on every write** and filters in PHP. That is fine for
  a small collection and a few writers, and it is why `sqlite` exists.

Relations, joins, migrations, an explicit transaction API and an active-record `Model` layer are
out of scope.

## Helpers

Globally available, no imports:

```php
view('home', ['user' => $user])          // render a template
json(['ok' => true], 201)
response('hi', 200, [...])
redirect('/login')
abort(404, 'Not found.')                 // throws; returns never
request('key') / request()               // current Request
session('key') / session(['k' => 'v'])
cache('key') / cache(['k' => 'v'], 60)   // ttl in seconds, null = forever
db('users') / db()                       // a collection / the whole store
config('debug') / env('APP_ENV')
validate($v, [...]) / validateMany([...])
```

`session()`, `cache()` and `db()` with no argument return the shared instance, which carries the
fuller API (`remember`, `flash`, `increment`, `pull`, `collections`, and so on).

## Conventions

- `declare(strict_types=1);` in every file.
- Type every parameter and return. The router reads those types to build routes and cast path
  values, so an untyped parameter is not merely untidy, it changes behaviour.
- Constructor property promotion; readonly value objects.
- Endpoints stay thin, and group into traits as they multiply.

## Deliberately absent

Middleware, a DI container, a PSR-7 bridge, `#[Route]` attributes and CLI scaffolding are all
out of scope by choice, not by omission. Do not reach for them or hand-roll a substitute
without being asked.

The same goes for everything an ORM would add on top of data storage: relations and joins,
migrations and schemas, an explicit transaction API, and an active-record `Model` base class.
Collections are schemaless and stay that way. If a query needs more than
[Data storage](#data-storage) offers, that is a signal you have outgrown this layer, not an
invitation to hand-roll one.
