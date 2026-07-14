# Batframe

A tiny, Flask-like PHP microframework. You extend one class, write public methods, and each
method becomes an HTTP endpoint whose route is inferred from its name. No route files, no
decorators, no config to get started, but every moving part is swappable when you outgrow the
defaults.

Batframe is for the project that is too small for Laravel or Symfony: a quick JSON API, a
handful of endpoints, or a small static site.

```php
use Batframe\Batframe;
use Batframe\Http\Request;
use Batframe\Http\Response;

class App extends Batframe
{
    public function index()                     // GET /
    {
        return view('home', ['name' => 'World']);
    }

    public function getUsers()                  // GET /users
    {
        return ['users' => ['ada', 'linus']];   // arrays become JSON automatically
    }

    public function getUser(int $id)            // GET /user/{id}
    {
        return ['id' => $id];
    }

    public function postUsers(Request $request) // POST /users
    {
        return Response::json(['created' => $request->input('name')], 201);
    }
}

(new App())->run();
```

## Requirements

- PHP 8.1+

## Install

The fastest way to start is the skeleton, which scaffolds a ready-to-run app
(route traits, Blade views, static pages, a front controller, and `.env`):

```bash
composer create-project michaelyousrie/batframe-skeleton my-app
cd my-app
composer serve
```

See [batframe-skeleton](https://github.com/michaelyousrie/batframe-skeleton) for
details. To add Batframe to an existing project instead:

```bash
composer require michaelyousrie/batframe
```

## The routing convention

The route is derived entirely from the method name and its parameters.

| Method                                   | Route                            |
| ---------------------------------------- | -------------------------------- |
| `index()`                                | `GET /`                          |
| `getUsers()`                             | `GET /users`                     |
| `getUserProfile()`                       | `GET /user/profile`              |
| `getUser(int $id)`                       | `GET /user/{id}`                 |
| `getUserPost(int $userId, int $postId)`  | `GET /user/post/{userId}/{postId}` |
| `postUsers()`                            | `POST /users`                    |
| `putUser(int $id)`                       | `PUT /user/{id}`                 |
| `deleteUser(int $id)`                    | `DELETE /user/{id}`              |

Rules:

- The method name must **start with an HTTP verb**: `get`, `post`, `put`, `patch`, `delete`,
  `head`, `options`. That sets the HTTP method.
- The remaining PascalCase words become `/`-separated path segments, lowercased.
- **No auto-pluralization**: the path is taken literally from the name. `getUser()` is `/user`;
  for `/users` name it `getUsers()`.
- `index()` is special-cased to `GET /`.
- A public method that does **not** start with a verb is treated as an internal helper and is
  **not** exposed as a route. Handy for shared logic; keep truly private helpers `private`.

### Route parameters

- Typed scalar parameters (`int`, `string`, `float`, `bool`) become `{placeholder}` path
  segments in declared order. `int`/`float` also constrain the segment, so `/user/abc` will not
  match `getUser(int $id)`.
- A parameter typed `Request` is injected and may appear in any position:
  `postUsers(Request $request)`.

### Return values

Whatever a handler returns is turned into a response:

| Return value                       | Response                    |
| ---------------------------------- | --------------------------- |
| a `Response`                       | sent as-is                  |
| an `array` / scalar / `JsonSerializable` | `application/json`    |
| a `string`                         | `text/html`                 |
| `null`                             | `204 No Content`            |

### Grouping routes into traits

Routes don't have to live directly on your app class. Any verb-prefixed public
method composed in from a **trait** is registered exactly like an inline method,
so you can group related endpoints together and keep the app class tiny:

```php
trait UserRoutes
{
    public function getUsers()          { /* GET /users */ }
    public function getUser(int $id)    { /* GET /user/{id} */ }
    public function postUsers()         { /* POST /users */ }

    // verbless helper — shared by the routes above, not itself a route
    protected function formatName(string $name): string { /* ... */ }
}

class App extends Batframe
{
    use UserRoutes;
    use PageRoutes;
}
```

The same convention rules apply inside a trait (verb prefix required, verbless
methods are helpers). See [`example/src/Routes/`](example/src/Routes/) for a
working split.

## Requests

```php
$request->input('name', $default); // JSON body, then form body, then query string
$request->get('q');                // single value from the query string (GET data)
$request->query('q');              // single query param (alias of get())
$request->post('name');            // single value from the body (form OR JSON)
$request->form('name');            // form body only (never the JSON body)
$request->json('name');            // JSON body only (never the form body)
$request->allGet();                // every GET/query param as an array
$request->allQuery();              // alias of allGet()
$request->allPost();               // whole body as an array (form + JSON merged)
$request->all();                   // everything merged (query + body + JSON)
$request->only('name', 'email');   // just those inputs
$request->except('password');      // everything but those
$request->filled('name');          // present and not empty ("0" counts)
$request->boolean('active');       // "1"/"true"/"on"/"yes" => true
$request->integer('page', 1);      // cast with a fallback
$request->string('q');             // cast with a fallback
$request->json();                  // decoded JSON body as an array
$request->header('Authorization'); // case-insensitive
$request->bearerToken();           // Bearer token, or null
$request->method();                // "GET"
$request->path();                  // "/users"
$request->wantsJson();             // content negotiation
$request->ip();
```

You don't have to inject the `Request` to reach it. The `request()` helper returns
the request being handled anywhere in your app:

```php
request();               // the Batframe\Http\Request instance
request('name');         // input from the query string or the body
request('page', 1);      // with a fallback
request()->only('a', 'b');
```

## Responses

```php
Response::json($data, 200);
Response::html('<h1>Hi</h1>');
Response::text('plain');
Response::view('home', ['name' => 'World']);   // rendered through the view engine
Response::redirect('/login');
Response::file('/path/to/file.pdf');
Response::noContent();

// fluent
Response::json($data)->status(201)->header('X-Trace', 'abc');
```

Helper functions are available too: `view()`, `json()`, `response()`, `redirect()`,
`abort(404)`, `env()`, `config()`, `session()`, `cache()`, `request()`, `validate()`,
`validateMany()`.

## Sessions

Batframe wraps PHP's native file-based sessions in a small, intuitive helper. The
session starts lazily the first time you touch it, so no cookie is sent unless you
actually use it, and there's nothing to configure.

```php
session()->put('user_id', 42);
$id = session('user_id');              // 42
session(['theme' => 'dark', 'lang' => 'en']); // set several at once

session()->has('user_id');             // present and not null
session()->pull('cart');               // read and remove
session()->forget('user_id');
session()->push('items', $item);       // append to an array value
session()->increment('visits');        // handy counters
session()->flush();                    // clear everything

// flash data lives for the next request only
session()->flash('status', 'Saved!');
$status = session('status');

session()->regenerate();               // new id, e.g. after login
session()->destroy();                  // end the session
```

`session()` with no argument returns the `Batframe\Helpers\Session` instance;
`session('key')` reads a value; `session(['key' => 'value'])` writes.

## Cache

Batframe ships a small file-based cache, in the same spirit as the session helper. Every entry
carries its own time-to-live, so you decide per item whether it is short-lived or sticks around.
Pass a ttl in seconds, or `null` (the default) to keep it until you forget it.

```php
cache()->put('report', $data, 3600);   // expires in an hour
cache()->forever('config', $config);   // never expires
$data = cache('report');               // read, or null when missing/stale
$data = cache('report', $fallback);    // read with a default

cache(['a' => 1, 'b' => 2], 600);      // write several at once, ttl in seconds
cache()->add('lock', 1, 30);           // write only if absent (returns bool)
cache()->has('report');                // present and not expired
cache()->pull('report');               // read and remove
cache()->increment('hits');            // handy counters (ttl preserved)
cache()->forget('report');
cache()->flush();                      // clear everything

// compute on miss, then cache for 10 minutes
$users = cache()->remember('users', 600, fn () => load_users());
$config = cache()->rememberForever('config', fn () => load_config());
```

`cache()` with no argument returns the `Batframe\Helpers\Cache` instance; `cache('key')` reads;
`cache(['key' => 'value'], $ttl)` writes. Entries live as files under the app's cache directory,
so they survive across requests. Constructing `new Cache()` with no directory gives you a
request-scoped, in-memory store instead.

## Validation

Batframe validates a single value against a list of rules. Rules are built from the
`Rule` class, so the call site stays type-safe with no magic strings. `validate()`
returns `true` when every rule passes, or throws a `ValidationException` (HTTP 422)
that Batframe's error pipeline renders for you, with the messages in the JSON body.

```php
use Batframe\Validation\Rule;

validate($email, [Rule::required(), Rule::email()]);
validate('123', [Rule::min(2), Rule::max(4)]);      // string length 3 -> passes

// validate many values at once: the key is the value, the value is its rules
validateMany([
    'ada@example.com' => [Rule::required(), Rule::email()],
    'abc123'          => [Rule::alphaNum()],
]);
```

The available rules: `Rule::required()`, `Rule::nullable()`, `Rule::string()`,
`Rule::integer()`, `Rule::boolean()`, `Rule::numeric()`, `Rule::alphaNum()`,
`Rule::alpha()`, `Rule::email()`, `Rule::url()`, `Rule::min($n)`, `Rule::max($n)`,
`Rule::between($min, $max)`, `Rule::in([...])`, `Rule::regex($pattern)`.

`nullable` lets a `null` value skip the remaining rules; `required` rejects an empty
value (`null`, `''`, or `[]`). The size rules (`min`/`max`/`between`) measure a string's
length, an array's element count, or an int/float's value; a numeric *string* is measured
by length, so cast it first if you mean to compare the value:
`validate((int) $qty, [Rule::between(1, 5)])`.

`validate()` with no arguments returns the `Batframe\Validation\Validator` instance,
whose `passes()` / `fails()` methods do the same checks without throwing.

## Views (Blade)

Templating is powered by [BladeOne](https://github.com/EFTEC/BladeOne), a dependency-free engine
with the Blade syntax you know (`@extends`, `@section`, `@foreach`, `{{ $var }}`). Templates live
in the `views/` directory:

```blade
{{-- views/home.blade.php --}}
@extends('layouts.app')
@section('content')
    <h1>Hello, {{ $name }}!</h1>
@endsection
```

The engine is swappable, implement `Batframe\View\ViewEngine` and pass it in via the
`view_engine` config key (or `Response::setViewEngine(...)`).

## Static pages

Drop a Blade or HTML file in the `pages/` directory and it is served automatically when a request
path matches its name, with no controller method needed. `pages/about.blade.php` answers
`/about`; `pages/index.blade.php` answers `/`. This makes small static sites trivial.

## Configuration & environment

A `.env` file in your project root is loaded automatically (via `vlucas/phpdotenv`). Read values
with `env('KEY', $default)`; literals like `true`/`false`/`null` are coerced. `APP_DEBUG=true`
turns on detailed error pages.

Pass overrides to the constructor:

```php
new App([
    'base_path' => __DIR__ . '/..',
    'views'     => 'resources/views',
    'pages'     => 'resources/pages',
    'cache'     => 'storage/cache',
    'debug'     => true,
]);
```

By convention, if you don't set `base_path`, Batframe assumes your app class lives in
`<project>/src/` and derives the project root from there.

## Project layout

```
public/index.php     # front controller (document root)
src/App.php          # your class extends Batframe
views/               # Blade templates
pages/               # auto-routed static pages
storage/cache/       # compiled Blade cache (writable)
.env                 # environment
```

The front controller is a one-liner:

```php
require __DIR__ . '/../vendor/autoload.php';
(new App())->run();
```

## Running locally

```bash
php -S localhost:8000 -t public
```

A complete, runnable example lives in [`example/`](example/).

## Testing

```bash
composer install
vendor/bin/phpunit
```

## Error handling

Throw an `HttpException` (or call `abort()`) to abort with a status code:

```php
use Batframe\Http\HttpException;

throw new HttpException(403, 'Forbidden');
abort(404);
```

Errors are rendered as JSON or HTML based on content negotiation. When `APP_DEBUG=true`, 500s
include the exception class, location and stack trace; in production they show a generic page.

## License

MIT
