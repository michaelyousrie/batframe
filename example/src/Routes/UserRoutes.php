<?php

declare(strict_types=1);

namespace Example\Routes;

use Batframe\Http\Request;
use Batframe\Http\Response;

/**
 * User-related endpoints, grouped into a trait so related routes live together.
 */
trait UserRoutes
{
    /** GET /users */
    public function getUsers(): array
    {
        return ['users' => ['ada', 'linus', 'grace']];
    }

    /** GET /user/{id}/{name} */
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
     * A verbless helper — used by postUsers(), NOT exposed as a route (same rule
     * as for methods declared directly on the controller).
     */
    public function formatName(string $name): string
    {
        return ucfirst($name);
    }
}
