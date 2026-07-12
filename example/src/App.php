<?php

declare(strict_types=1);

namespace Example;

use Batframe\Batframe;
use Example\Routes\PageRoutes;
use Example\Routes\SessionRoutes;
use Example\Routes\UserRoutes;

/**
 * The entire demo application. Rather than declaring every endpoint inline, the
 * routes are grouped into traits under src/Routes/ and composed here. Each
 * trait's verb-prefixed public methods become endpoints just like methods
 * defined directly on this class.
 */
class App extends Batframe
{
    use PageRoutes;
    use UserRoutes;
    use SessionRoutes;
}
