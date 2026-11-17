<?php

declare(strict_types=1);

namespace App\Controller;

use App\Game\Core\EndOfRequest;
use Symfony\Component\HttpFoundation\Response;

/**
 * Page rendering for the game area controllers (login, game, account, admin).
 *
 * A page action returns the Response of render(), passing the template variables as context (assign() still works and
 * is merged underneath it, for variables several steps of the action share):
 *
 *     return $this->render('game/page.empire.default.html.twig', ['planets' => $planets]);
 *
 * Actions answer with a Response instead of ending the request: render(), redirectResponse(), redirectToRoute(),
 * jsonResponse() (and the message page of the area, messageResponse()). A helper that returns the Response
 * "early" hands it to the action (`if ($response = $this->check()) { return $response; }`).
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

    /**
     * renders a page template (path below templates/) inside the area layout
     *
     * @param array<string, mixed> $context template variables; win over assign()ed ones
     */
    protected function render(string $template, array $context = []): Response
    {
        $context = array_replace($this->viewData, $context, $this->layoutData($template), [
            'layout_template' => $this->layoutTemplate(),
            'scripts' => $this->pageScripts,
            'execscript' => implode("\n", $this->inlineScripts),
        ]);

        return $this->gameView()->response($template, $context);
    }

    /** hook run before a response is built (the public pages save their state here) */
    protected function beforeResponse(): void {}

    /** JSON answer of an action (Content-Type stays text/html like the former echo of json_encode) */
    protected function jsonResponse(mixed $data): Response
    {
        $this->beforeResponse();

        return new Response(json_encode($data), 200, ['Content-Type' => 'text/html; charset=UTF-8']);
    }

    /** redirect to $url relative to the universe directory ("account", "./?code=3"), or an absolute URL when $external */
    protected function redirectResponse(string $url, bool $external = false): Response
    {
        $this->beforeResponse();

        return $this->http()->redirectResponse($url, $external);
    }

    /**
     * URL of a route (see App\Routing\GameRoute) inside the current universe directory.
     *
     * @param array<string, scalar> $parameters placeholders; other entries become the query string
     */
    protected function routeUrl(string $route, array $parameters = []): string
    {
        return $this->router->generate($route, $parameters, \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * redirect to a route
     *
     * @param array<string, scalar> $parameters
     */
    protected function redirectToRoute(string $route, array $parameters = []): Response
    {
        return $this->redirectResponse($this->routeUrl($route, $parameters), true);
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
