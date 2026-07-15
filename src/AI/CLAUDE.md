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
config('debug') / env('APP_ENV')
validate($v, [...]) / validateMany([...])
```

`session()` and `cache()` with no argument return the shared instance, which carries the fuller
API (`remember`, `flash`, `increment`, `pull`, and so on).

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
