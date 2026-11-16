<?php

namespace App\Routing;

use Symfony\Component\Routing\Attribute\Route;

/**
 * Route for a game page: /<area>?page=<page> (case-insensitive, like the former page dispatcher),
 * also with a /uniN/ prefix. With $default the page also answers when no ?page= is given (the
 * area's default page). The entry script name only identifies the area.
 *
 *   #[GamePage('game.php', 'overview')]  ->  /game?page=overview (and the former /game.php?page=overview)
 *   #[GamePage('index.php', 'news')]     ->  /?page=news
 */
#[\Attribute(\Attribute::TARGET_CLASS | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class GamePage extends Route
{
    /**
     * @param string $script    entry script, only its basename selects the area (index = login, game, admin, account, CombatReport)
     * @param string $page      value of `?page=`, matched case-insensitively through a route condition
     * @param bool   $default   also match when `page` is missing or empty
     * @param bool   $uniPrefix variant under /uni{N}/ (universe prefix, N is passed as route attribute `uni`)
     *
     * Route name is `game_<area>_<page lowercased>[_uni]`, which CsrfPolicy and LocaleSubscriber rely on.
     */
    public function __construct(string $script, string $page, bool $default = false, bool $uniPrefix = false)
    {
        $matchPage = sprintf("(request.query.get('page') ~ '') matches '/^%s$/i'", preg_quote($page, '/'));
        $condition = $default ? "(request.query.get('page') ~ '') == '' or " . $matchPage : $matchPage;
        $area = basename($script, '.php');

        // clean URLs (/game, /admin, /account, / for the login); the former /<area>.php still matches
        // through the optional {_ext} suffix (GET requests to it are redirected by LegacyUrlRedirectListener)
        if ($script === 'index.php') {
            $path = $uniPrefix ? '/uni{uni}/{_ext}' : '/';
            $requirements = $uniPrefix ? ['uni' => '\d+', '_ext' => '(?:index\.php)?'] : [];
        } else {
            $path = ($uniPrefix ? '/uni{uni}/' : '/') . $area . '{_ext}';
            $requirements = ($uniPrefix ? ['uni' => '\d+'] : []) + ['_ext' => '(?:\.php)?'];
        }

        parent::__construct(
            path: $path,
            name: sprintf('game_%s_%s%s', $area, strtolower($page), $uniPrefix ? '_uni' : ''),
            requirements: $requirements,
            defaults: ['_ext' => ''],
            condition: $condition,
            priority: 10,
        );
    }
}
