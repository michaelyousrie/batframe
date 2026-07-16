<?php

declare(strict_types=1);

namespace Example\Routes;

use Batframe\Http\Request;
use Batframe\Http\Response;
use Batframe\Validation\Rule;

/**
 * User-related endpoints, grouped into a trait so related routes live together.
 *
 * These are backed by db('users'), so they survive a restart. Which driver is
 * underneath is a matter of DB_DRIVER in .env, and nothing in this file knows
 * or cares which one answered.
 */
trait UserRoutes
{
    /** GET /users */
    public function getUsers(): array
    {
        return ['users' => db('users')->find(orderBy: 'name')];
    }

    /** GET /user/{id} */
    public function getUser(int $id): array
    {
        return db('users')->get($id) ?? abort(404, "There is no user {$id}.");
    }

    /** POST /users */
    public function postUsers(Request $request): Response
    {
        // A failure here is already a 422 with an `errors` key; there is
        // nothing to catch and nothing to re-throw.
        $request->validate('name', [Rule::required(), Rule::string(), Rule::max(50)]);

        $user = db('users')->insert([
            'name' => $this->formatName(strtolower($request->input('name'))),
        ]);

        return Response::json($user, 201);
    }

    /** DELETE /user/{id} */
    public function deleteUser(int $id): Response
    {
        if (!db('users')->delete($id)) {
            abort(404, "There is no user {$id}.");
        }

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
