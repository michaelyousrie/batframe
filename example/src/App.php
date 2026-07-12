<?php

declare(strict_types=1);

namespace Example;

use Batframe\Batframe;
use Batframe\Http\Request;
use Batframe\Http\Response;

/**
 * The entire demo application. Every public method below is an endpoint; the
 * route is inferred from the method name.
 */
class App extends Batframe
{
    /** GET / */
    public function index(): Response
    {
        return view('home', ['name' => 'World']);
    }

    /** GET /users */
    public function getUsers(): array
    {
        return ['users' => ['ada', 'linus', 'grace']];
    }

    /** GET /user/{id} */
    public function getUser(int $id, string $name): array
    {
        return ['id' => $id, 'name' => $name];
    }

    /** POST /users */
    public function postUsers(Request $request): Response
    {
        $name = $request->input('name', 'anonymous');

        return Response::json(['created' => $this->formatName(strtolower($name))], 201);
    }

    /** DELETE /user/{id} */
    public function deleteUser(int $id): Response
    {
        return Response::noContent();
    }

    /**
     * GET /boom — demonstrates error handling. With APP_DEBUG=true you get a
     * detailed page; otherwise a generic 500.
     */
    public function getBoom(): never
    {
        throw new \RuntimeException('Something went wrong on purpose.');
    }

    /**
     * A plain public helper (no verb prefix) — NOT exposed as a route.
     */
    public function formatName(string $name): string
    {
        return ucfirst($name);
    }
}
