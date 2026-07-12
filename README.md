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

```bash
composer require batframe/batframe
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

## Requests

```php
$request->input('name', $default); // JSON body, then form body, then query string
$request->query('q');              // single query param
$request->json();                  // decoded JSON body as an array
$request->all();                   // everything merged
$request->header('Authorization'); // case-insensitive
$request->bearerToken();           // Bearer token, or null
$request->method();                // "GET"
$request->path();                  // "/users"
$request->wantsJson();             // content negotiation
$request->ip();
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
`abort(404)`, `env()`, `config()`.

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
