<?php

declare(strict_types=1);

namespace Batframe;

use Batframe\DataStorage\DataStorageException;
use Batframe\DataStorage\Json\JsonStore;
use Batframe\DataStorage\Sqlite\SqliteStore;
use Batframe\DataStorage\Store;
use Batframe\Http\HttpException;
use Batframe\Http\Request;
use Batframe\Http\Response;
use Batframe\Routing\RouteResolver;
use Batframe\Routing\Router;
use Batframe\Support\Config;
use Batframe\Support\Environment;
use Batframe\Validation\ValidationException;
use Batframe\View\BladeOneEngine;
use Batframe\View\ViewEngine;
use JsonSerializable;
use ReflectionClass;
use Throwable;

/**
 * The base application class. Extend it, add public methods named after HTTP
 * verbs, and call run(). Each such method becomes an endpoint whose route is
 * inferred from its name.
 *
 *   class App extends Batframe
 *   {
 *       public function index()        { return view('home'); }   // GET /
 *       public function getUsers()     { return ['a', 'b']; }     // GET /users
 *       public function getUser($id)   { return ['id' => $id]; }  // GET /user/{id}
 *   }
 *
 *   (new App())->run();
 */
abstract class Batframe
{
    protected Config $config;

    protected Router $router;

    protected ?ViewEngine $viewEngine = null;

    protected string $basePath;

    protected string $viewPath;

    protected string $pagePath;

    protected string $cachePath;

    protected ?Store $store = null;

    protected string $databaseDriver;

    protected ?string $databasePath;

    protected bool $debug;

    /** The currently running application, for the config()/view() helpers. */
    private static ?Batframe $current = null;

    private bool $booted = false;

    /**
     * @param array<string, mixed> $config Optional overrides:
     *        base_path, views, pages, cache, debug, view_engine, database,
     *        database_path.
     */
    public function __construct(array $config = [])
    {
        $this->config = new Config($config);

        $this->basePath = rtrim((string) ($config['base_path'] ?? $this->guessBasePath()), '/\\');

        $this->viewPath = $this->resolvePath($config['views'] ?? 'views');
        $this->pagePath = $this->resolvePath($config['pages'] ?? 'pages');
        $this->cachePath = $this->resolvePath($config['cache'] ?? 'storage/cache');

        Environment::load($this->basePath);

        $this->debug = (bool) ($config['debug'] ?? Environment::get('APP_DEBUG', false));

        if (isset($config['view_engine']) && $config['view_engine'] instanceof ViewEngine) {
            $this->viewEngine = $config['view_engine'];
        }

        // `database` is either a driver name or a Store to use as-is, the same
        // way `view_engine` takes an engine. Both fall back to the environment,
        // so an app can run on JSON locally and SQLite in production without a
        // line of code changing.
        if (isset($config['database']) && $config['database'] instanceof Store) {
            $this->store = $config['database'];
        }

        $this->databaseDriver = strtolower((string) (
            is_string($config['database'] ?? null) ? $config['database'] : Environment::get('DB_DRIVER', 'json')
        ));

        $this->databasePath = $config['database_path'] ?? Environment::get('DB_PATH', null);

        $this->router = new Router();
    }

    /**
     * The running application instance (null outside of a request lifecycle).
     */
    public static function current(): ?Batframe
    {
        return self::$current;
    }

    public function config(): Config
    {
        return $this->config;
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * The resolved cache directory (used by the `cache()` helper as its store).
     */
    public function cachePath(): string
    {
        return $this->cachePath;
    }

    /**
     * The resolved database location: a directory of JSON files for the json
     * driver, a single file for sqlite. Relative paths resolve against the base
     * path, as everywhere else.
     */
    public function databasePath(): string
    {
        $default = $this->databaseDriver === 'sqlite' ? 'storage/database.sqlite' : 'storage/database';
        $path = $this->databasePath ?? $default;

        // ':memory:' is SQLite's own name for "no file at all", so it is passed
        // through rather than resolved into a path that would never be used.
        if ($path === ':memory:') {
            return $path;
        }

        return $this->resolvePath($path);
    }

    /**
     * The app's store (used by the `db()` helper). Built on first use from the
     * configured driver, unless a Store was handed in as config.
     */
    public function store(): Store
    {
        if ($this->store === null) {
            $this->store = match ($this->databaseDriver) {
                'json' => new JsonStore($this->databasePath()),
                'sqlite' => new SqliteStore($this->databasePath()),
                default => throw new DataStorageException(
                    "'{$this->databaseDriver}' is not a database driver Batframe knows: use 'json' or 'sqlite', "
                    . 'or pass a Store as the `database` config.',
                ),
            };
        }

        return $this->store;
    }

    public function viewEngine(): ViewEngine
    {
        if ($this->viewEngine === null) {
            $paths = array_values(array_unique(array_filter([
                is_dir($this->viewPath) ? $this->viewPath : null,
                is_dir($this->pagePath) ? $this->pagePath : null,
            ])));

            if ($paths === []) {
                $paths = [$this->viewPath];
            }

            $this->viewEngine = new BladeOneEngine($paths, $this->cachePath);
        }

        return $this->viewEngine;
    }

    /**
     * Boot the app: register the view engine, resolve the route table. Called
     * automatically by run()/handle(); idempotent.
     */
    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        self::$current = $this;
        Response::setViewEngine($this->viewEngine());

        $routes = (new RouteResolver())->resolve($this);
        $this->router->addMany($routes);

        $this->booted = true;
    }

    /**
     * Handle a request and send the response to the client.
     */
    public function run(?Request $request = null): void
    {
        $request ??= Request::capture();

        $this->handle($request)->send();
    }

    /**
     * Handle a request and return the response without sending it. Useful in
     * tests and when embedding Batframe inside another stack.
     */
    public function handle(Request $request): Response
    {
        $this->boot();

        Request::swap($request);

        try {
            return $this->dispatch($request);
        } catch (Throwable $exception) {
            return $this->renderException($exception, $request);
        }
    }

    // ------------------------------------------------------------------
    // Dispatch
    // ------------------------------------------------------------------

    private function dispatch(Request $request): Response
    {
        $matched = $this->router->match($request);

        if ($matched !== null) {
            $arguments = $matched->route->buildArguments($matched->parameters, $request);
            $result = $this->{$matched->route->handler}(...$arguments);

            return $this->toResponse($result);
        }

        // A path that matches under other verbs is a 405, not a 404.
        $allowed = $this->router->allowedMethods($request->path());

        if ($allowed !== []) {
            throw new HttpException(405, 'Method Not Allowed.', ['Allow' => implode(', ', $allowed)]);
        }

        // Fall back to serving a static page from the pages directory.
        $page = $this->pageResponse($request->path());

        if ($page !== null) {
            return $page;
        }

        throw new HttpException(404, 'Not Found.');
    }

    /**
     * Normalise a handler's return value into a Response.
     */
    private function toResponse(mixed $result): Response
    {
        if ($result instanceof Response) {
            return $result;
        }

        if ($result === null) {
            return Response::noContent();
        }

        if (is_string($result)) {
            return Response::html($result);
        }

        if (is_array($result) || is_scalar($result) || $result instanceof JsonSerializable) {
            return Response::json($result);
        }

        // stdClass and other objects: JSON-encode their public shape.
        return Response::json($result);
    }

    /**
     * Render a matching page from the pages directory, or null when none.
     */
    private function pageResponse(string $path): ?Response
    {
        $relative = trim($path, '/');

        if ($relative === '') {
            $relative = 'index';
        }

        // Reject path traversal.
        if (str_contains($relative, '..')) {
            return null;
        }

        $blade = $this->pagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative) . '.blade.php';
        if (is_file($blade)) {
            return Response::view($relative);
        }

        $html = $this->pagePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative) . '.html';
        if (is_file($html)) {
            return Response::html((string) file_get_contents($html));
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Error handling
    // ------------------------------------------------------------------

    private function renderException(Throwable $exception, Request $request): Response
    {
        $status = $exception instanceof HttpException ? $exception->getStatusCode() : 500;
        $headers = $exception instanceof HttpException ? $exception->getHeaders() : [];

        if ($request->wantsJson()) {
            $payload = ['error' => Response::phrase($status), 'message' => $exception->getMessage()];

            if ($exception instanceof ValidationException) {
                $payload['errors'] = $exception->errors();
            }

            if ($this->debug && $status >= 500) {
                $payload['exception'] = $exception::class;
                $payload['file'] = $exception->getFile() . ':' . $exception->getLine();
                $payload['trace'] = explode("\n", $exception->getTraceAsString());
            }

            return Response::json($payload, $status)->withHeaders($headers);
        }

        // Never leak the raw message of a non-HttpException in production; only
        // an HttpException carries a message meant for the client.
        $message = $exception instanceof HttpException && $exception->getMessage() !== ''
            ? $exception->getMessage()
            : Response::phrase($status);

        $html = $this->debug && $status >= 500
            ? $this->debugErrorPage($exception, $status)
            : $this->errorPage($status, $message);

        return Response::html($html, $status)->withHeaders($headers);
    }

    /**
     * The HTML error page for a status. An app overrides the built-in page by
     * convention: a view named `errors/{status}` (say `errors/404`) answers
     * that status, and `errors/error` catches anything without its own file —
     * discovered by filename, exactly like the pages directory. A view that is
     * missing, or that throws while rendering, falls through to the built-in
     * page, so a broken error template can never loop or take the error path
     * down with it.
     */
    private function errorPage(int $status, string $message): string
    {
        foreach (["errors/{$status}", 'errors/error'] as $view) {
            if (!$this->viewEngine()->exists($view)) {
                continue;
            }

            try {
                return $this->viewEngine()->render($view, ['status' => $status, 'message' => $message]);
            } catch (Throwable) {
                break;
            }
        }

        return $this->genericErrorPage($status, $message);
    }

    /**
     * The built-in production error page: the status code as the hero, the
     * message beneath. Self-contained on purpose (system fonts, inline CSS, no
     * network requests), so it renders even when everything else is broken.
     */
    private function genericErrorPage(int $status, string $message): string
    {
        $title = htmlspecialchars($status . ' ' . Response::phrase($status), ENT_QUOTES);
        $message = htmlspecialchars($message, ENT_QUOTES);

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>{$title}</title>
<style>
:root{--ink:#0A0B0F;--line:#23262F;--mist:#9AA0AC;--chalk:#ECEEF2;--signal:#6E5BFF;--brass:#F2B33D;
--mono:ui-monospace,"Cascadia Code","JetBrains Mono",Menlo,Consolas,monospace;
--sans:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
*{box-sizing:border-box}html,body{height:100%;margin:0}
body{background:var(--ink);color:var(--chalk);font-family:var(--sans);display:flex;align-items:center;
justify-content:center;padding:2rem;background-image:radial-gradient(52rem 34rem at 50% -22%,rgba(110,91,255,.15),transparent 60%)}
.box{text-align:center;max-width:32rem}
.code{font-family:var(--mono);font-weight:600;font-size:clamp(5rem,22vw,9rem);line-height:.95;letter-spacing:-.045em;color:var(--signal);margin:0}
.phrase{font-size:1.3rem;font-weight:600;letter-spacing:-.01em;margin:1rem 0 0}
.rule{width:2.2rem;height:2px;background:var(--brass);border:0;margin:1.5rem auto 0;opacity:.85}
.mark{margin-top:2.25rem;font-family:var(--mono);font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mist)}
.mark b{color:var(--brass);font-weight:600}
</style></head>
<body><div class="box"><p class="code">{$status}</p><p class="phrase">{$message}</p>
<hr class="rule"><p class="mark">powered by <b>batframe</b></p></div></body></html>
HTML;
    }

    /**
     * The built-in debug error page (debug mode, 5xx): a developer tool showing
     * the exception, where it was thrown, and the stack trace. Also
     * self-contained. Never shown to end users, since it exposes internals.
     */
    private function debugErrorPage(Throwable $exception, int $status): string
    {
        $fqcn = $exception::class;
        $slash = strrpos($fqcn, '\\');
        $namespace = $slash !== false ? htmlspecialchars(substr($fqcn, 0, $slash + 1), ENT_QUOTES) : '';
        $short = htmlspecialchars($slash !== false ? substr($fqcn, $slash + 1) : $fqcn, ENT_QUOTES);
        $title = htmlspecialchars($status . ' ' . $fqcn, ENT_QUOTES);
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES);
        $messageHtml = $message !== '' ? "<p class=\"message\">{$message}</p>" : '';
        $file = htmlspecialchars($exception->getFile(), ENT_QUOTES);
        $line = (int) $exception->getLine();

        // The trace is already HTML-escaped; wrapping the leading frame index of
        // each line only inserts spans around safe "#\d+" text.
        $trace = preg_replace(
            '/^(#\d+)/m',
            '<span class="idx">$1</span>',
            htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES),
        );

        return <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1"><title>{$title}</title>
<style>
:root{--ink:#0A0B0F;--panel-2:#0F1116;--line:#23262F;--mist:#9AA0AC;--chalk:#ECEEF2;--signal:#6E5BFF;--brass:#F2B33D;--danger:#F26D6D;
--mono:ui-monospace,"Cascadia Code","JetBrains Mono",Menlo,Consolas,monospace;
--sans:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
*{box-sizing:border-box}html,body{margin:0}
body{background:var(--ink);color:var(--chalk);font-family:var(--sans);line-height:1.5;-webkit-font-smoothing:antialiased}
.wrap{max-width:60rem;margin:0 auto;padding:3rem 1.5rem 4rem}
.tag{display:inline-flex;align-items:center;gap:.5rem;font-family:var(--mono);font-size:.72rem;letter-spacing:.18em;text-transform:uppercase;color:var(--danger);margin:0 0 1.1rem}
.tag .n{color:var(--ink);background:var(--danger);border-radius:.3rem;padding:.1rem .45rem;font-weight:600}
.type{font-family:var(--mono);font-size:clamp(1.5rem,4vw,2.15rem);font-weight:600;letter-spacing:-.02em;margin:0;color:var(--chalk);word-break:break-word}
.type .ns{color:var(--mist)}
.message{font-size:1.15rem;color:var(--chalk);margin:.9rem 0 0;max-width:60ch}
.loc{display:inline-flex;align-items:center;gap:.55rem;margin:1.4rem 0 0;font-family:var(--mono);font-size:.85rem;color:var(--mist);border:1px solid var(--line);border-radius:.5rem;padding:.5rem .8rem}
.loc b{color:var(--brass);font-weight:600}
.trace-h{font-family:var(--mono);font-size:.72rem;letter-spacing:.16em;text-transform:uppercase;color:var(--mist);margin:2.6rem 0 .8rem}
.trace{background:var(--panel-2);border:1px solid var(--line);border-radius:.7rem;padding:1rem 1.15rem;overflow-x:auto;margin:0}
.trace pre{margin:0;font-family:var(--mono);font-size:.82rem;line-height:1.85;color:#C9CDD6;white-space:pre}
.trace .idx{color:var(--signal)}
.foot{margin-top:2.4rem;font-family:var(--mono);font-size:.72rem;letter-spacing:.14em;text-transform:uppercase;color:var(--mist)}
.foot b{color:var(--brass);font-weight:600}
.foot .d{color:var(--line);margin:0 .5rem}
</style></head>
<body><div class="wrap">
<p class="tag"><span class="n">{$status}</span> Unhandled exception</p>
<h1 class="type"><span class="ns">{$namespace}</span>{$short}</h1>
{$messageHtml}
<span class="loc">at <b>{$file}</b>:{$line}</span>
<p class="trace-h">Stack trace</p>
<div class="trace"><pre>{$trace}</pre></div>
<p class="foot"><b>batframe</b><span class="d">&middot;</span>debug mode<span class="d">&middot;</span>set APP_DEBUG=false to hide this</p>
</div></body></html>
HTML;
    }

    // ------------------------------------------------------------------
    // Paths
    // ------------------------------------------------------------------

    private function resolvePath(string $path): string
    {
        if ($this->isAbsolutePath($path)) {
            return rtrim($path, '/\\');
        }

        return $this->basePath . DIRECTORY_SEPARATOR . trim($path, '/\\');
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, '/')
            || str_starts_with($path, '\\')
            || (bool) preg_match('#^[A-Za-z]:[\\\\/]#', $path);
    }

    private function guessBasePath(): string
    {
        $file = (new ReflectionClass($this))->getFileName();

        // Convention: the controller lives in <base>/src/, so the project root
        // is two directories up. Falls back to the current working directory.
        if (is_string($file)) {
            return dirname($file, 2);
        }

        return getcwd() ?: '.';
    }
}
