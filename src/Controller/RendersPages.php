<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Core\EndOfRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page rendering for the game area controllers (login, game, account, admin).
 *
 * A page action collects its template variables with assign() and returns render():
 *
 *     $this->assign(['planets' => $planets]);
 *     return $this->render('game/page.empire.default.html.twig');
 *
 * render() adds the area's layout data (navigation, clock, theme: layoutData()), the page scripts
 * and the layout to extend (`layout_template`, see the area's layout.<window>.html.twig), and
 * returns the Response. Page templates start with `{% extends layout_template %}`.
 *
 * Code that is not the action itself (a helper deep in the call chain, an error branch) can end
 * the request with display(), which throws the rendered page as App\Game\Core\EndOfRequest.
 *
 * Layout data wins over page variables of the same name, as it did with the former Smarty
 * wrapper. Escaping: see App\Twig\GameEscaping.
 */
trait RendersPages
{
    /** @var array<string, mixed> page variables collected by assign() */
    private array $viewData = [];

    /** @var list<string> page scripts below public/scripts/<area>/, e.g. "galaxy.js" */
    private array $pageScripts = [];

    /** @var list<string> inline JavaScript run once the page is ready (onPageInit) */
    private array $inlineScripts = [];

    /** window of the page: full, popup or ajax (the layout to use), set by the area base class */
    protected string $window = 'full';

    /** @param array<string, mixed> $variables */
    protected function assign(array $variables): void
    {
        $this->viewData = array_replace($this->viewData, $variables);
    }

    /** a page script (ES module) below public/scripts/<area>/; "../base/x.js" loads a classic script */
    protected function addScript(string $file): void
    {
        $this->pageScripts[] = $file;
    }

    /** JavaScript code run once the page is ready (code written by the controller, never user input) */
    protected function addInlineScript(string $code): void
    {
        $this->inlineScripts[] = $code;
    }

    protected function setWindow(string $window): void
    {
        $this->window = $window;
    }

    protected function getWindow(): string
    {
        return $this->window;
    }

    /** renders a page template (path below templates/) inside the area layout */
    protected function render(string $template): Response
    {
        $context = array_replace($this->viewData, $this->layoutData($template), [
            'layout_template' => $this->layoutTemplate(),
            'scripts' => $this->pageScripts,
            'execscript' => implode("\n", $this->inlineScripts),
        ]);

        return $this->gameView()->response($template, $context);
    }

    /** renders the page and ends the request (for code that cannot return the Response itself) */
    protected function display(string $template): never
    {
        throw EndOfRequest::withResponse($this->render($template));
    }

    /** the layout the page extends: <area>/layout.<window>.html.twig */
    protected function layoutTemplate(): string
    {
        return static::templateArea() . '/layout.' . $this->window . '.html.twig';
    }

    /** template folder of the area below templates/ (game, login, account, admin) */
    abstract protected static function templateArea(): string;

    /**
     * Variables of the area layout (navigation, clock, theme, ...); they override page variables
     * of the same name.
     *
     * @return array<string, mixed>
     */
    abstract protected function layoutData(string $template): array;
}
