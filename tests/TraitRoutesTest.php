<?php

declare(strict_types=1);

namespace Batframe\Tests;

use Batframe\Batframe;
use Batframe\Http\Request;
use Batframe\Routing\RouteResolver;
use Batframe\View\ViewEngine;
use PHPUnit\Framework\TestCase;

/**
 * A group of related routes defined in a trait.
 */
trait BlogRoutes
{
    public function getPosts(): array
    {
        return ['posts' => []];
    }

    public function getPost(int $id): array
    {
        return ['id' => $id];
    }

    public function postPosts(Request $request): array
    {
        return ['created' => true];
    }

    // Verbless helper inside the trait: must NOT be routed.
    public function slugify(string $value): string
    {
        return strtolower($value);
    }
}

class TraitController extends Batframe
{
    use BlogRoutes;

    public function index(): array
    {
        return ['home' => true];
    }
}

final class TraitRoutesTest extends TestCase
{
    /** @return list<string> "VERB path" */
    private function paths(): array
    {
        $routes = (new RouteResolver())->resolve(new TraitController(['base_path' => __DIR__]));

        return array_map(fn ($r) => $r->verb . ' ' . $r->path, $routes);
    }

    public function test_trait_methods_are_routed_like_inline_methods(): void
    {
        $paths = $this->paths();

        $this->assertContains('GET /posts', $paths);
        $this->assertContains('GET /post/{id}', $paths);
        $this->assertContains('POST /posts', $paths);
        // The controller's own inline route still works alongside trait routes.
        $this->assertContains('GET /', $paths);
    }

    public function test_verbless_trait_helper_is_not_routed(): void
    {
        $routes = (new RouteResolver())->resolve(new TraitController(['base_path' => __DIR__]));

        foreach ($routes as $route) {
            $this->assertNotSame('slugify', $route->handler);
        }
    }

    public function test_a_trait_route_dispatches_end_to_end(): void
    {
        $engine = new class implements ViewEngine {
            public function render(string $template, array $data = []): string
            {
                return $template;
            }

            public function exists(string $template): bool
            {
                return false;
            }
        };

        $app = new TraitController(['base_path' => __DIR__, 'view_engine' => $engine]);

        $response = $app->handle(new Request('GET', '/post/7'));

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('{"id":7}', $response->getBody());
    }
}
