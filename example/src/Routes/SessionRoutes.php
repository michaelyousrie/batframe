<?php

declare(strict_types=1);

namespace Example\Routes;

use Batframe\Http\Response;

/**
 * Session demo routes. Values persist across requests in PHP's default file
 * session, keyed by the session cookie.
 */
trait SessionRoutes
{
    /** GET /counter — increments a per-session visit counter. */
    public function getCounter(): array
    {
        return [
            'visits' => session()->increment('visits'),
            'session_id' => session()->id(),
        ];
    }

    /** DELETE /counter — clears the counter. */
    public function deleteCounter(): Response
    {
        session()->forget('visits');

        return Response::noContent();
    }
}
