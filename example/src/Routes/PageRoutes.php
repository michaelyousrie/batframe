<?php

declare(strict_types=1);

namespace Example\Routes;

use Batframe\Http\Response;

/**
 * Page-serving endpoints, grouped into a trait. Every verb-prefixed public
 * method here becomes a route on any class that `use`s this trait, exactly as
 * if it were declared on the class itself.
 */
trait PageRoutes
{
    /** GET / */
    public function index(): Response
    {
        return view('home', ['name' => 'World']);
    }

    /**
     * GET /boom — demonstrates error handling. With APP_DEBUG=true you get a
     * detailed page; otherwise a generic 500.
     */
    public function getBoom(): never
    {
        throw new \RuntimeException('Something went wrong on purpose.');
    }
}
