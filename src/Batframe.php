<?php

declare(strict_types=1);

namespace Batframe;

use Batframe\Http\HttpException;
use Batframe\Http\Request;
use Batframe\Http\Response;
use Batframe\Routing\RouteResolver;
use Batframe\Routing\Router;
use Batframe\Support\Config;
use Batframe\Support\Environment;
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

    protected bool $debug;

    /** The currently running application, for the config()/view() helpers. */
    private static ?Batframe $current = null;

    private bool $booted = false;

    /**
     * @param array<string, mixed> $config Optional overrides:
     *        base_path, views, pages, cache, debug, view_engine.
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

            if ($this->debug && $status >= 500) {
                $payload['exception'] = $exception::class;
                $payload['file'] = $exception->getFile() . ':' . $exception->getLine();
                $payload['trace'] = explode("\n", $exception->getTraceAsString());
            }

            return Response::json($payload, $status)->withHeaders($headers);
        }

        $html = $this->debug && $status >= 500
            ? $this->debugErrorPage($exception, $status)
            : $this->genericErrorPage($status, $exception);

        return Response::html($html, $status)->withHeaders($headers);
    }

    private function genericErrorPage(int $status, Throwable $exception): string
    {
        $title = $status . ' ' . Response::phrase($status);
        $message = $exception instanceof HttpException && $exception->getMessage() !== ''
            ? htmlspecialchars($exception->getMessage(), ENT_QUOTES)
            : htmlspecialchars(Response::phrase($status), ENT_QUOTES);

        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$title}</title>"
            . '<style>body{font-family:system-ui,sans-serif;display:flex;min-height:100vh;margin:0;'
            . 'align-items:center;justify-content:center;background:#0f172a;color:#e2e8f0}'
            . '.b{text-align:center}.b h1{font-size:4rem;margin:0}.b p{color:#94a3b8}</style></head>'
            . "<body><div class=\"b\"><h1>{$status}</h1><p>{$message}</p></div></body></html>";
    }

    private function debugErrorPage(Throwable $exception, int $status): string
    {
        $type = htmlspecialchars($exception::class, ENT_QUOTES);
        $message = htmlspecialchars($exception->getMessage(), ENT_QUOTES);
        $location = htmlspecialchars($exception->getFile() . ':' . $exception->getLine(), ENT_QUOTES);
        $trace = htmlspecialchars($exception->getTraceAsString(), ENT_QUOTES);

        return "<!doctype html><html><head><meta charset=\"utf-8\"><title>{$status} {$type}</title>"
            . '<style>body{font-family:system-ui,sans-serif;margin:0;background:#0f172a;color:#e2e8f0}'
            . '.h{background:#7f1d1d;padding:1.5rem 2rem}.h h1{margin:0;font-size:1.25rem}'
            . '.h p{margin:.5rem 0 0;color:#fecaca}.m{padding:1.5rem 2rem}'
            . 'pre{background:#1e293b;padding:1rem;border-radius:8px;overflow:auto;font-size:.85rem}'
            . '.loc{color:#94a3b8}</style></head>'
            . "<body><div class=\"h\"><h1>{$type}</h1><p>{$message}</p></div>"
            . "<div class=\"m\"><p class=\"loc\">{$location}</p><pre>{$trace}</pre></div></body></html>";
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
