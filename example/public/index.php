<?php

declare(strict_types=1);

// The single front controller. Point your web server's document root here.
require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__) . '/src/App.php';

(new Example\App())->run();
