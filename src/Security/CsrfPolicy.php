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
 *   GET_RULES; the front end sends them as POST (data-method="post" links, post() helper), the rule is
 *   the safety net for hand-built links (the token may then travel as query parameter "_csrf").
 *
 * The token is a Symfony CSRF token with id TOKEN_ID (per session, masked), submitted as header
 * X-CSRF-Token (fetch/XHR) or as form/query field "_csrf" (see public/scripts/app/csrf.js).
 *
 * Route names of game pages are "game_<area>_<page>[_uni]" (App\Routing\GamePage), area being one of
 * index (login), game, account, admin.
 */
final class CsrfPolicy
{
    public const TOKEN_ID = 'game';
    public const FIELD = '_csrf';
    public const HEADER = 'X-CSRF-Token';

    private const UNSAFE_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * State-changing GET requests. Each rule: area, page ("*" = any page of the area) and optional
     * conditions on the query string: "mode"/"action" (value or list of values, case-insensitive), "has"
     * (any of these parameters present), "unless" (map parameter => values that exempt the request).
     *
     * @var list<array{area: string, page: string, mode?: string|list<string>, action?: string|list<string>, has?: list<string>, unless?: array<string, list<string>>}>
     */
    private const GET_RULES = [
        // --- game
        ['area' => 'game', 'page' => 'logout'],
        ['area' => 'game', 'page' => 'alliance', 'mode' => ['cancelApply', 'close']],
        ['area' => 'game', 'page' => 'alliance', 'mode' => 'apply', 'has' => ['text']],
        ['area' => 'game', 'page' => 'alliance', 'mode' => ['create', 'circular'], 'action' => 'send'],
        ['area' => 'game', 'page' => 'alliance', 'mode' => 'admin', 'action' => [
            'close', 'transfer', 'sendAnswerToApply', 'permissionsSend', 'rank', 'membersSave', 'membersKick',
            'diplomacyAccept', 'diplomacyReject', 'diplomacyDelete', 'diplomacyCreateProcessor',
        ]],
        ['area' => 'game', 'page' => 'alliance', 'mode' => 'admin', 'has' => ['send']],
        ['area' => 'game', 'page' => 'buddyList', 'mode' => ['send', 'delete', 'accept']],
        ['area' => 'game', 'page' => 'fleetTable', 'action' => ['sendfleetback', 'acs']],
        ['area' => 'game', 'page' => 'fleetAjax'],
        ['area' => 'game', 'page' => 'information', 'mode' => ['sendFleet', 'destroyMissiles']],
        ['area' => 'game', 'page' => 'marketPlace', 'action' => 'buy'],
        ['area' => 'game', 'page' => 'messages', 'mode' => ['favMessage', 'deleteMessage', 'action', 'send', 'sendForward']],
        ['area' => 'game', 'page' => 'notes', 'mode' => ['insert', 'delete']],
        ['area' => 'game', 'page' => 'overview', 'mode' => ['rename', 'delete']],
        ['area' => 'game', 'page' => 'phalanx'],
        ['area' => 'game', 'page' => 'playerCard', 'mode' => 'toggleBlock'],
        ['area' => 'game', 'page' => 'rebuildProtectionAjax'],
        ['area' => 'game', 'page' => 'repairdock', 'has' => ['cmd']],
        ['area' => 'game', 'page' => 'report'],
        ['area' => 'game', 'page' => 'settings', 'mode' => ['send', 'toggleVacation', 'toggleDelete']],
        ['area' => 'game', 'page' => 'shipyard', 'has' => ['fmenge', 'auftr']],
        ['area' => 'game', 'page' => 'shipyard', 'mode' => 'saveFilters'],
        ['area' => 'game', 'page' => 'tutorial', 'mode' => ['skip', 'skipAll', 'claim', 'fullfill']],
        ['area' => 'game', 'page' => 'resources', 'mode' => 'send'],
        ['area' => 'game', 'page' => 'fleetDealer', 'mode' => 'send'],
        ['area' => 'game', 'page' => 'battleSimulator', 'mode' => 'send'],
        ['area' => 'game', 'page' => 'fleetStep1', 'mode' => 'saveShortcuts'],
        ['area' => 'game', 'page' => 'ticket', 'mode' => 'send'],
        // --- account (the board additionally checks its own token on writes)
        ['area' => 'account', 'page' => 'logout'],
        ['area' => 'account', 'page' => 'accountSettings', 'mode' => ['send', 'toggleDelete']],
        // --- admin: every action of the admin pages except the read-only views
        ['area' => 'admin', 'page' => 'logout'],
        ['area' => 'admin', 'page' => 'module', 'has' => ['mode']],
        ['area' => 'admin', 'page' => 'clearCache'],
        ['area' => 'admin', 'page' => 'statUpdate'],
        ['area' => 'admin', 'page' => 'flyingFleet', 'has' => ['sendfleetback', 'sendfleetsback']],
        ['area' => 'admin', 'page' => 'multiIP', 'has' => ['action']],
        ['area' => 'admin', 'page' => 'rights', 'has' => ['action']],
        ['area' => 'admin', 'page' => 'support', 'mode' => 'send'],
        ['area' => 'admin', 'page' => '*', 'has' => ['action'], 'unless' => ['action' => ['overview', 'show', 'detail', 'edit', 'create', 'check', 'getFileList']]],
    ];

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

    /** Must this request prove itself with the token? */
    public function requiresToken(Request $request): bool
    {
        if (!$this->isGameRequest($request)) {
            return false;
        }
        if (in_array($request->getMethod(), self::UNSAFE_METHODS, true)) {
            return true;
        }

        return $this->matchesGetRule($request);
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
     * triggered by a plain link that GET_RULES does not know about. Browsers without Fetch Metadata
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
        $route = (string) $request->attributes->get('_route', '');

        return preg_match('/^game_([a-z]+)_([a-z0-9]+)/', $route, $m) === 1 ? [$m[1], $m[2]] : ['', ''];
    }

    private function matchesGetRule(Request $request): bool
    {
        [$area, $page] = $this->areaAndPage($request);
        foreach (self::GET_RULES as $rule) {
            if ($rule['area'] !== $area || ($rule['page'] !== '*' && strtolower($rule['page']) !== $page)) {
                continue;
            }
            if (isset($rule['mode']) && !$this->queryIn($request, 'mode', $rule['mode'])) {
                continue;
            }
            if (isset($rule['action']) && !$this->queryIn($request, 'action', $rule['action'])) {
                continue;
            }
            if (isset($rule['has']) && !$this->hasAny($request, $rule['has'])) {
                continue;
            }
            foreach ($rule['unless'] ?? [] as $param => $values) {
                if ($this->queryIn($request, $param, $values)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    /** @param string|list<string> $values */
    private function queryIn(Request $request, string $param, string|array $values): bool
    {
        $given = $request->query->get($param);
        if (!is_scalar($given)) {
            return false;
        }

        return in_array(strtolower((string) $given), array_map('strtolower', (array) $values), true);
    }

    /** @param list<string> $params */
    private function hasAny(Request $request, array $params): bool
    {
        foreach ($params as $param) {
            if ($request->query->has($param)) {
                return true;
            }
        }

        return false;
    }
}
