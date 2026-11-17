<?php

declare(strict_types=1);

namespace App\Routing;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Route of one page action (a public method of a game controller) with a readable path:
 *
 *   #[GameRoute('index', 'register', 'register', 'show')]                       ->  GET  /register          login_register_show
 *   #[GameRoute('index', 'register', 'register', 'send', ['POST'])]             ->  POST /register          login_register_send
 *   #[GameRoute('account', 'account/board/{id}', 'board', 'post', requirements: ['id' => '\d+'])]
 *                                                                              ->  GET  /account/board/12  account_board_post
 *
 * The path is relative to the site root (or the `/uniN/` universe prefix, route attribute `uni`, kept as before; an
 * empty path is the start page). Route name: `<area>_<page>_<mode>` (page and mode in snake_case, area index is
 * called login), e.g. `login_lost_password_send`. The defaults `_area` (index, game, account, admin: the game mode,
 * see MODES), `_page` and `_mode` (lower case) tell CsrfPolicy, GameRateLimits and the game bootstrap what the
 * request is, so no query parameter has to be inspected. Path placeholders reach the action through
 * `$this->input()` like query and form fields do; never name one `uni`.
 */
#[\Attribute(\Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class GameRoute extends Route
{
    /** Game mode per area (route default `_area` -> boot mode, see GameControllerListener). */
    public const MODES = ['index' => 'LOGIN', 'game' => 'INGAME', 'admin' => 'ADMIN', 'account' => 'ACCOUNT'];

    /** Optional universe prefix of the path (`uni5/`), route attribute `uni`; see GameControllerListener. */
    public const UNI_REQUIREMENT = '(?:uni\d+/)?';

    /** @var array<string, string> area -> route name prefix */
    private const NAME_PREFIX = ['index' => 'login', 'game' => 'game', 'account' => 'account', 'admin' => 'admin'];

    /**
     * @param string                $area         index (public pages), game, account or admin
     * @param string                $path         e.g. "register", "account/board/{id}", "" (start page)
     * @param string                $page         page name (the former `?page=`), camelCase allowed
     * @param string                $mode         action name (the former `?mode=`), e.g. show, send
     * @param list<string>          $methods      allowed HTTP methods (GET also answers HEAD)
     * @param array<string, string> $requirements regular expressions of the placeholders
     * @param array<string, mixed>  $defaults     more placeholder defaults
     * @param int                   $priority     route order (higher first)
     */
    public function __construct(
        string $area,
        string $path,
        string $page,
        string $mode = 'show',
        array $methods = ['GET'],
        array $requirements = [],
        array $defaults = [],
        int $priority = 10,
    ) {
        parent::__construct(
            path: '/{uni}' . $path,
            name: sprintf('%s_%s_%s', self::NAME_PREFIX[$area], self::snake($page), self::snake($mode)),
            requirements: ['uni' => self::UNI_REQUIREMENT] + $requirements,
            defaults: $defaults + ['uni' => '', '_area' => $area, '_page' => strtolower($page), '_mode' => strtolower($mode)],
            methods: $methods,
            priority: $priority,
        );
    }

    /** lostPassword -> lost_password */
    private static function snake(string $name): string
    {
        return strtolower((string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $name));
    }
}
