<?php

declare(strict_types=1);

namespace App\Security;

use App\Controller\GameControllerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Decides which requests of the game, account and admin areas must carry the CSRF token, and where
 * the token may be found. One central place instead of per-controller checks:
 *
 * - every unsafe method (POST, PUT, PATCH, DELETE) to a game controller needs the token;
 * - safe methods (GET, HEAD) need it only when they change state. Those few endpoints are listed in
 *   GET_ROUTES; the front end sends them as POST (data-method="post" links, post() helper), the rule is
 *   the safety net for hand-built links (the token may then travel as query parameter "_csrf").
 *
 * The token is a Symfony CSRF token with id TOKEN_ID (per session, masked), submitted as header
 * X-CSRF-Token (fetch/XHR) or as form/query field "_csrf" (see public/scripts/app/csrf.js).
 *
 * Pages are routes (App\Routing\GameRoute, names "<area>_<page>_<mode>") known by their route attributes
 * `_area`, `_page`, `_mode`; their state-changing GET actions are listed in GET_ROUTES, the rest of them are POST-only
 * routes. Area is one of index (login), game, account, admin.
 */
final class CsrfPolicy
{
    public const TOKEN_ID = 'game';
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /** route names (GameRoute) of state-changing GET actions */
    private const GET_ROUTES = ['account_logout_show', 'game_phalanx_show', 'game_logout_show'];

    /** public pages of the authenticated areas that may be opened from other sites (battle reports, board, wiki login) */
    private const CROSS_SITE_PAGES = ['raport', 'board', 'wikilogin'];

    /** Does the request belong to a game controller (the pages the token protects)? */
    public function isGameRequest(Request $request): bool
    {
        $controller = $request->attributes->get('_controller');
        if (!is_string($controller)) {
            return false;
        }
        $class = explode('::', $controller, 2)[0];

        return class_exists($class) && is_subclass_of($class, GameControllerInterface::class);
    }

    /** The firewall's logout route (/logout): not a game controller, but ending a login is state-changing too. */
    public function isLogoutRoute(Request $request): bool
    {
        return $request->attributes->get('_route') === 'app_logout';
    }

    /** Must this request prove itself with the token? */
    public function requiresToken(Request $request): bool
    {
        if ($this->isLogoutRoute($request)) {
            return true;
        }
        if (!$this->isGameRequest($request)) {
            return false;
        }
        if (in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return true;
        }

        return $this->isStateChangingGet($request);
    }

    /** The submitted token: header first (fetch/XHR), then body field, then query parameter. */
    public function submittedToken(Request $request): string
    {
        $token = $request->headers->get(self::HEADER);
        if (!is_string($token) || $token === '') {
            $token = $request->request->get(self::FIELD, $request->query->get(self::FIELD));
        }

        return is_string($token) ? $token : '';
    }

    /**
     * Cross-site navigation (Sec-Fetch-Site: cross-site, top-level GET) into a page of an authenticated
     * area that is not meant to be linked from outside: the last line of defence against state changes
     * triggered by a plain link that GET_ROUTES does not know about. Browsers without Fetch Metadata
     * (header missing) are not affected.
     */
    public function isForeignNavigation(Request $request): bool
    {
        if (!$this->isGameRequest($request) || !in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return false;
        }
        if ($request->headers->get('Sec-Fetch-Site') !== 'cross-site' || $request->headers->get('Sec-Fetch-Mode') !== 'navigate') {
            return false;
        }
        [$area, $page] = $this->areaAndPage($request);

        return in_array($area, ['game', 'account', 'admin'], true) && !in_array($page, self::CROSS_SITE_PAGES, true);
    }

    /** @return array{0: string, 1: string} area (index, game, account, admin) and lower-case page of the matched route */
    public function areaAndPage(Request $request): array
    {
        $area = $request->attributes->get('_area');
        $page = $request->attributes->get('_page');

        return [is_string($area) ? $area : '', is_string($page) ? $page : ''];
    }

    /** Whether a GET request is a state-changing action reachable by link (route listed in GET_ROUTES), which needs a token. */
    private function isStateChangingGet(Request $request): bool
    {
        return in_array($request->attributes->get('_route'), self::GET_ROUTES, true);
    }
}
