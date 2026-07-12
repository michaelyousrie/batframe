<?php

declare(strict_types=1);

// The single front controller. Point your web server's document root here.
require dirname(__DIR__, 2) . '/vendor/autoload.php';

// The example has no autoloader of its own, so load the route traits before the
// class that composes them. (In a real app you'd let Composer PSR-4 do this.)
require dirname(__DIR__) . '/src/Routes/PageRoutes.php';
require dirname(__DIR__) . '/src/Routes/UserRoutes.php';
require dirname(__DIR__) . '/src/Routes/SessionRoutes.php';
require dirname(__DIR__) . '/src/App.php';

(new Example\App())->run();
