<?php

declare(strict_types=1);

namespace Batframe\View;

/**
 * A templating engine. Swap the default {@see BladeOneEngine} for any
 * implementation (Twig, plain PHP, ...) by binding it on the app or via
 * Response::setViewEngine().
 */
interface ViewEngine
{
    /**
     * Render a template to a string.
     *
     * @param string               $template Dot- or slash-separated template name (no extension).
     * @param array<string, mixed> $data     Variables made available to the template.
     */
    public function render(string $template, array $data = []): string;

    /**
     * Whether a template exists for the given name.
     */
    public function exists(string $template): bool;
}
