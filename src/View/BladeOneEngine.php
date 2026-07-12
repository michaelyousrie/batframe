<?php

declare(strict_types=1);

namespace Batframe\View;

use eftec\bladeone\BladeOne;

/**
 * The default view engine, wrapping eftec/bladeone. Renders `.blade.php`
 * templates referenced by dot or slash notation ("home", "layouts.app").
 */
final class BladeOneEngine implements ViewEngine
{
    private BladeOne $blade;

    /** @var list<string> */
    private array $viewPaths;

    /**
     * @param string|list<string> $viewPath  One or more directories holding templates.
     * @param string              $cachePath Writable directory for compiled templates.
     */
    public function __construct(string|array $viewPath, string $cachePath, ?int $mode = null)
    {
        $this->viewPaths = is_array($viewPath) ? array_values($viewPath) : [$viewPath];

        if (!is_dir($cachePath)) {
            @mkdir($cachePath, 0775, true);
        }

        $this->blade = new BladeOne($viewPath, $cachePath, $mode ?? BladeOne::MODE_AUTO);
    }

    /**
     * Access the underlying BladeOne instance to register directives, share
     * globals, configure auth, etc.
     */
    public function blade(): BladeOne
    {
        return $this->blade;
    }

    public function render(string $template, array $data = []): string
    {
        return $this->blade->run($template, $data);
    }

    public function exists(string $template): bool
    {
        $relative = str_replace('.', '/', $template) . '.blade.php';

        foreach ($this->viewPaths as $path) {
            if (is_file(rtrim($path, '/\\') . DIRECTORY_SEPARATOR . $relative)) {
                return true;
            }
        }

        return false;
    }
}
