<?php

namespace App\Routing;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Route for a game page: /<area>?page=<page> (case-insensitive, like the former page dispatcher),
 * also under a /uniN/ prefix. With $default the page also answers when no ?page= is given (the
 * area's default page).
 *
 *   #[GamePage('game', 'overview')]  ->  /game?page=overview
 *   #[GamePage('index', 'news')]     ->  /?page=news
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class GamePage extends Route
{
    /** Game mode and entry script name per area (route default `_area` -> mode, see GameControllerListener). */
    public const MODES = ['index' => 'LOGIN', 'game' => 'INGAME', 'admin' => 'ADMIN', 'account' => 'ACCOUNT'];

    /** Optional universe prefix of the path (`uni5/`), route attribute `uni`; see GameControllerListener. */
    public const UNI_REQUIREMENT = '(?:uni\d+/)?';

    /**
     * @param string $area    index (login), game, admin or account
     * @param string $page    value of `?page=`, matched case-insensitively through a route condition
     * @param bool   $default also match when `page` is missing or empty
     *
     * Route name is `game_<area>_<page lowercased>`, which CsrfPolicy and LocaleSubscriber rely on.
     */
    public function __construct(string $area, string $page, bool $default = false)
    {
        $matchPage = sprintf("(request.query.get('page') ~ '') matches '/^%s$/i'", preg_quote($page, '/'));
        $condition = $default ? "(request.query.get('page') ~ '') == '' or " . $matchPage : $matchPage;

        parent::__construct(
            path: '/{uni}' . ($area === 'index' ? '' : $area),
            name: sprintf('game_%s_%s', $area, strtolower($page)),
            requirements: ['uni' => self::UNI_REQUIREMENT],
            defaults: ['uni' => '', '_area' => $area],
            condition: $condition,
            priority: 10,
        );
    }
}
