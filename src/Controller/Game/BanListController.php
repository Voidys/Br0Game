<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * List of banned players.
 */
#[\App\Routing\GamePage('game.php', 'banList')]
#[\App\Routing\GamePage('game.php', 'banList', uniPrefix: true)]
class BanListController extends AbstractGameController
{
    public static $requireModule = 'MODULE_BANLIST';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Ban list of the universe (newest first, paged; request: side). Shows start and end of the bans in the universe timezone.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $page  	= $this->http()->_GP('side', 1);
        $db 	= $this->db;

        $sql = "SELECT COUNT(*) as count FROM %%USERS_BANNED%% WHERE universe = :universe ORDER BY time DESC;";
        $banCount = $db->selectSingle($sql, [
            ':universe'	=> $this->universe()->current(),
        ], 'count');

        $maxPage	= ceil($banCount / BANNED_USERS_PER_PAGE);
        $page		= max(1, min($page, $maxPage));

        $sql = "SELECT * FROM %%USERS_BANNED%% WHERE universe = :universe ORDER BY time DESC LIMIT :offset, :limit;";
        $banResult = $db->select($sql, [
            ':universe'	=> $this->universe()->current(),
            ':offset'   => (($page - 1) * BANNED_USERS_PER_PAGE),
            ':limit'    => BANNED_USERS_PER_PAGE,
        ]);

        $banList	= [];

        foreach ($banResult as $banRow) {
            $banList[]	= [
                'player'	=> $banRow['who'],
                'theme'		=> $banRow['theme'],
                'from'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $banRow['time'], $USER['timezone']),
                'to'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $banRow['longer'], $USER['timezone']),
                'admin'		=> $banRow['author'],
                'mail'		=> $banRow['email'],
                'info'		=> $LNG->format('bn_writemail', $banRow['author']),
            ];
        }

        $this->assign([
            'banList'	=> $banList,
            'banCount'	=> $banCount,
            'page'		=> $page,
            'maxPage'	=> $maxPage,
        ]);

        return $this->render('game/page.banList.default.html.twig');
    }
}
