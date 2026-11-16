<?php

namespace App\Game\Admin;

use Symfony\Component\HttpFoundation\Response;

/** AdminPageResponder backed by closures of the controller (whose page methods are protected). */
final class ClosureAdminResponder implements AdminPageResponder
{
    /**
     * @param \Closure(string|\Twig\Markup, string|false, int, bool): never $message
     * @param \Closure(array<string, mixed>): void                 $assign
     * @param \Closure(string): Response                           $render
     */
    public function __construct(
        private readonly \Closure $message,
        private readonly \Closure $assign,
        private readonly \Closure $render,
    ) {}

    /** Delegates to the controller's message page (ends the request). */
    public function message(string|\Twig\Markup $message, string|false $redirect = false, int $delay = 3, bool $fatal = false): never
    {
        ($this->message)($message, $redirect, $delay, $fatal);
    }

    /** Delegates to the controller's template variable assignment. */
    public function assign(array $variables): void
    {
        ($this->assign)($variables);
    }

    /** Renders an admin template with the variables assigned so far. */
    public function render(string $template): Response
    {
        return ($this->render)($template);
    }
}
