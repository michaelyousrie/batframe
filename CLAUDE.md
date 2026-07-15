# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## The contract

@src/AI/CLAUDE.md

That file describes how the framework *behaves*: the naming convention, what a handler may
return, the pages fallback, the helpers, validation semantics. It ships inside the Composer
package, and applications built on Batframe import the very same file out of their `vendor/`,
so their AI guidance always matches the version they installed.

**It is the framework's public contract. If you change how the framework behaves, changing that
file is part of the change, not follow-up work.** Everything below is about developing the
framework itself and stays here, because it is not true in a consuming project.

(`/CLAUDE.md` is `export-ignore`d in `.gitattributes` so this file stays out of the dist
tarball. `src/AI/CLAUDE.md` is not ignored, which is what puts it in `vendor/`. Check
`git check-attr export-ignore -- <path>` before moving either.)

## What this is

Batframe is a Composer library (`michaelyousrie/batframe`, namespace `Batframe\`, PHP 8.1+): a
Flask-like microframework where a developer extends the `Batframe` base class and each public
method becomes an HTTP endpoint whose route is **inferred from the method name**. There is no
route table or config to author.

## Commands

```bash
composer install                       # install deps (eftec/bladeone, vlucas/phpdotenv, phpunit)
vendor/bin/phpunit                     # run the full suite
vendor/bin/phpunit tests/DispatchTest.php          # one file
vendor/bin/phpunit --filter test_wrong_verb_is_405 # one test by name
php -S localhost:8000 -t example/public            # run the example app
composer validate --no-check-publish   # validate composer.json (version warning is expected/harmless)
```

## Architecture (request lifecycle)

The whole framework is a single pipeline; understanding `src/Batframe.php` plus the routing trio
is enough to work anywhere in it.

1. **Boot** (`Batframe::boot()`, idempotent): binds the `ViewEngine` statically onto `Response`
   (so `Response::view()` and the `view()` helper work anywhere), sets `self::$current` for the
   `config()`/`view()` helpers, and builds the route table via `RouteResolver`.
2. **Resolve** (`src/Routing/RouteResolver.php`) — the heart of the framework. It reflects the
   subclass's public methods and compiles each into a `Route` (verb + regex + parameter-binding
   descriptors). See the convention rules below.
3. **Dispatch** (`Batframe::dispatch()`): `Router::match()` finds a route by verb+path, the
   `Route` casts captured path values and injects the `Request`, then the handler is invoked as a
   real method on `$this`. If no route matches: a path that matches under other verbs is a **405**
   (with `Allow` header); otherwise Batframe falls back to serving a static page from `pages/`;
   otherwise **404**.
4. **Normalize** (`Batframe::toResponse()`): a handler may return a `Response`, an array/scalar/
   `JsonSerializable` (→ JSON), a string (→ HTML), or null (→ 204).
5. **Errors**: `handle()` wraps dispatch in a try/catch. `HttpException` carries a status code and
   headers; anything else is a 500. `renderException()` does content negotiation
   (`Request::wantsJson()`) and, when `debug` is on, includes the exception class/file/trace.

`run()` = `handle()` + `send()`. `handle(Request): Response` returns without emitting, which is
how tests drive the app (see `tests/DispatchTest.php`).

## How the naming convention is implemented

The convention itself is in the contract, imported above. What follows is how it is built, which
is only interesting from inside this repo.

`RouteResolver::splitVerb()` / `segmentsFromName()` / `bindParameter()` do the work:

- Methods declared on `Batframe` itself are excluded via `getDeclaringClass()`, which is what
  keeps the framework's own public API (`boot()`, `handle()`, `run()`, `config()`, `router()`,
  `cachePath()`, `viewEngine()`) from becoming endpoints. Verbless public methods are simply
  never matched by `splitVerb()`; the example's `formatName()` is called by `postUsers()` that
  way.
- `getMethods()` includes trait-composed methods, and a trait method's `getDeclaringClass()` is
  the *using* class, so trait routes resolve exactly like inline ones (see `example/src/Routes/`).
  A trait used by `Batframe` itself would still be excluded, because its methods report
  `Batframe` as the declaring class. Pinned by `tests/TraitRoutesTest.php`.
- Constraints come from the PHP type: `int` gives `\d+`, `float` gives `\d+(?:\.\d+)?`, anything
  else `[^/]+`. Path params are appended after the name-derived segments, never interleaved.
- `Route` is a readonly value object exposing public `$verb`, `$path` (human-readable, with
  `{placeholders}`), `$regex`, `$handler` and `$parameters`. `RouteResolver::resolve()` is public
  and accepts a class-string, so consumers can enumerate the table without booting an app. The
  skeleton's `batbelt routes` relies on exactly that, so treat those five properties and
  `resolve()`'s signature as public API.

If you change routing semantics, `RouteResolver` is the single place to do it,
`tests/RouteResolverTest.php` pins the expected mappings, and `src/AI/CLAUDE.md` is where the
new behaviour gets described.

## Extension seams

- **View engine**: `Batframe\View\ViewEngine` interface; default `BladeOneEngine` wraps BladeOne.
  Swap by passing a `view_engine` in the constructor config or `Response::setViewEngine()`. Note
  BladeOne is configured with **both** the `views/` and `pages/` directories as roots, so static
  pages can `@extends` layouts that live under `views/`.
- **Config/paths**: the constructor takes an array (`base_path`, `views`, `pages`, `cache`,
  `debug`, `view_engine`). Relative paths resolve against `base_path`. When `base_path` is
  omitted it is guessed as two directories above the subclass file (i.e. the app class is assumed
  to live in `<project>/src/`).
- **Helpers** (`src/helpers.php`, autoloaded via composer `files`): `env()`, `config()`, `view()`,
  `json()`, `response()`, `redirect()`, `abort()`, `session()`, `cache()`, `request()`,
  `validate()`, `validateMany()`.
  `config()`/`view()` reach the running app via `Batframe::current()`; `request()` reaches the
  request being handled via `Request::current()` (bound in `Batframe::handle()`, mirroring the
  `Session`/`Cache` swappable-singleton pattern, so `Request::swap()` drives it in tests).
- **Sessions** (`src/Helpers/Session.php`, namespace `Batframe\Helpers`): wraps native file
  sessions, starts lazily, supports flash/increment/push/regenerate/destroy. The `session()`
  helper: no arg → the shared `Session` instance, `session('k')` reads, `session(['k'=>'v'])`
  writes. A shared singleton via `Session::instance()`; tests use an array-backed
  `new Session(false)` and `Session::swap()` to avoid real OS sessions. Pinned by
  `tests/SessionTest.php`.
- **Cache** (`src/Helpers/Cache.php`, namespace `Batframe\Helpers`): file-based key/value cache
  with a per-item ttl (seconds; null = forever, `<= 0` = expired-now). Supports
  put/get/has/add/forever/remember/rememberForever/pull/increment/flush. Entries are
  `serialize()`d to `<sha256(key)>.cache` files under the app cache dir
  (`Batframe::cachePath()` + `/data`, kept separate from BladeOne's compiled views). The `cache()`
  helper mirrors `session()`: no arg → the shared `Cache`, `cache('k')` reads,
  `cache(['k'=>'v'], $ttl)` writes with an optional ttl. A shared singleton via
  `Cache::instance()`; `new Cache()` (no directory) is a request-scoped in-memory store, and the
  constructor takes an injectable clock so `Cache::swap()` + a fake clock make ttl tests
  deterministic. Pinned by `tests/CacheTest.php`.
- **Validation** (`src/Validation/`, namespace `Batframe\Validation`): single-value validation
  with a **type-safe, no-magic-strings** call site. `Rule` is a readonly value object built via
  static factories (`Rule::required/nullable/string/integer/boolean/numeric/alphaNum/alpha/email/
  url/min/max/between/in/regex`); each carries a predicate closure, a precomputed message, and
  `isNullable`/`isRequired` flags. `Validator` is a swappable singleton (`instance()`/`swap()`,
  mirroring `Session`/`Cache`): `validate($v, $rules): true` throws `ValidationException` (422) on
  failure, `passes()`/`fails()` are the non-throwing predicates. The `validate()` helper: no arg →
  the `Validator`, `validate($v, $rules)` → true-or-throw. `validateMany([$value => $rules, ...])`
  runs every entry (does **not** stop at the first failure) and aggregates all failures into one
  `ValidationException`, keyed by entry — note PHP array-key coercion applies to the values.
  Evaluation order: `nullable` short-circuits a `null` to valid; then `required` fails on "empty"
  (`null`/`''`/`[]`); then remaining rules in listed order. **Size rules** (`min`/`max`/`between`)
  measure by the value's own type — string → char length (`mb_strlen`), array → count, `int`/
  `float` → numeric value — so a numeric *string* is measured by length (cast to compare value:
  `validate((int) $v, [Rule::between(1,5)])`). Failure messages live only on
  `ValidationException::errors()`; `Batframe::renderException()` adds an `errors` key to the JSON
  payload for it. `confirmed`/cross-field rules are out of scope (single value has no siblings).
  `Request::validate($key, $rules)` (i.e. `request()->validate('name', [...])`) is sugar for
  `validate(request($key), $rules)`: it resolves the value **through the `request()` helper** — not a
  fixed accessor, so it tracks whatever `request()` resolves from — and validates that value, not the
  key. Pinned by `tests/RequestTest.php`.

## Conventions in this codebase

- `declare(strict_types=1)` in every file; constructor property promotion; readonly value objects
  (`Route`, `MatchedRoute`).
- Deferred v1 scope (documented in the plan, intentionally absent): middleware pipeline, CLI
  scaffolding, PSR-7 bridge, `#[Route]` attribute layer, DI container. `RouteResolver` is kept as
  a clean seam so an attribute layer can be added without touching dispatch.
