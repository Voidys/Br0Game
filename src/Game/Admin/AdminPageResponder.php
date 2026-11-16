<?php

namespace App\Game\Admin;

use Symfony\Component\HttpFoundation\Response;

/** The page output an admin tab handler needs from its controller: message page, template variables, rendering. */
interface AdminPageResponder
{
    /** Shows a message page with optional redirect and ends the request. */
    public function message(string|\Twig\Markup $message, string|false $redirect = false, int $delay = 3, bool $fatal = false): never;

    /** @param array<string, mixed> $variables */
    public function assign(array $variables): void;

    /** Renders the admin template (path below templates/, e.g. `admin/Foo.html.twig`) with the assigned variables. */
    public function render(string $template): Response;
}
