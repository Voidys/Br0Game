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

namespace App\Controller\Login;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/** Public ban list. */
#[\App\Routing\GamePage('index.php', 'banList')]
#[\App\Routing\GamePage('index.php', 'banList', uniPrefix: true)]
class BanListController extends AbstractLoginController
{
    public static $requireModule = 'MODULE_BANLIST';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Public ban list of the current universe, newest first, paged. Request: side (page number).
     * Reads USERS_BANNED and shows ban and release time in the universe timezone.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;

        $db = $this->db;

        $page  		= $this->http()->_GP('side', 1);

        $sql = "SELECT COUNT(*) as count FROM %%USERS_BANNED%% WHERE universe = :universe ORDER BY time DESC;";
        $banCount = $db->selectSingle($sql, [
            ':universe'	=> $this->universe()->current(),
        ], 'count');

        $maxPage	= ceil($banCount / BANNED_USERS_PER_PAGE);
        $page		= max(1, min($page, $maxPage));

        $sql = "SELECT * FROM %%USERS_BANNED%% WHERE universe = :universe ORDER BY time DESC LIMIT :offset, :limit;";
        $banResult = $db->select($sql, [
            ':universe'	=> $this->universe()->current(),
            ':offset'	=> (($page - 1) * BANNED_USERS_PER_PAGE),
            ':limit'	=> BANNED_USERS_PER_PAGE,
        ]);

        $banList	= [];

        foreach ($banResult as $banRow) {
            $banList[]	= [
                'player'	=> $banRow['who'],
                'theme'		=> $banRow['theme'],
                'from'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $banRow['time'], $this->configs->get()->timezone),
                'to'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $banRow['longer'], $this->configs->get()->timezone),
                'admin'		=> $banRow['author'],
                'mail'		=> $banRow['email'],
                'info'		=> $LNG->format('bn_writemail', $banRow['author']),
            ];
        }

        $universeSelect	= $this->getUniverseSelector();

        $this->assign([
            'universeSelect'	=> $universeSelect,
            'banList'			=> $banList,
            'banCount'			=> $banCount,
            'page'				=> $page,
            'maxPage'			=> $maxPage,
        ]);

        return $this->render('login/page.banList.default.html.twig');
    }
}
