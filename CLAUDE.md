# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

Batframe is a Composer library (`batframe/batframe`, namespace `Batframe\`, PHP 8.1+): a
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

## The naming convention (the one thing to internalize)

Implemented in `RouteResolver::splitVerb()` / `segmentsFromName()` / `bindParameter()`:

- A method is only a route if it **starts with an HTTP verb** (`get/post/put/patch/delete/head/
  options`) or is exactly `index` (→ `GET /`). Verbless public methods are treated as internal
  helpers and are **not** routed — the example's `formatName()` is called by `postUsers()` this
  way. Methods declared on `Batframe` itself are excluded via `getDeclaringClass()`.
- Remaining PascalCase words become lowercased, `/`-separated path segments. **No
  auto-pluralization** — the path is literal (`getUser` → `/user`, `getUsers` → `/users`).
- Typed scalar params become `{placeholder}` segments in declared order; `int`/`float` also
  constrain the regex. A `Request`-typed param is injected (any position), not routed.
  `getUser(int $id, string $name)` → `GET /user/{id}/{name}`.
- **Trait methods are routed too.** `getMethods()` includes trait-composed methods, and a trait
  method's `getDeclaringClass()` is the *using* class — so routes can be grouped into traits
  (see `example/src/Routes/`). A trait used by `Batframe` itself would still be excluded because
  its methods report `Batframe` as the declaring class. Pinned by `tests/TraitRoutesTest.php`.

If you change routing semantics, `RouteResolver` is the single place to do it, and
`tests/RouteResolverTest.php` pins the expected mappings.

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
  `json()`, `response()`, `redirect()`, `abort()`, `session()`, `cache()`. `config()`/`view()`
  reach the running app via `Batframe::current()`.
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

## Conventions in this codebase

- `declare(strict_types=1)` in every file; constructor property promotion; readonly value objects
  (`Route`, `MatchedRoute`).
- Deferred v1 scope (documented in the plan, intentionally absent): middleware pipeline, CLI
  scaffolding, PSR-7 bridge, `#[Route]` attribute layer, DI container. `RouteResolver` is kept as
  a clean seam so an attribute layer can be added without touching dispatch.
