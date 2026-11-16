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
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Search for players, planets and alliances (form, result list, autocomplete data).
 */
#[\App\Routing\GamePage('game.php', 'search')]
#[\App\Routing\GamePage('game.php', 'search', uniPrefix: true)]
class SearchController extends AbstractGameController
{
    public static $requireModule = 'MODULE_SEARCH';

    public function initialize(): void
    {
        parent::initialize();
    }

    private function _getSearchList($searchMode, $searchText, $maxResult)
    {
        $db = $this->db;

        $limit		= $maxResult === -1 ? '' : 'LIMIT ' . ((int) $maxResult);

        $searchList	= [];

        switch ($searchMode) {
            case 'playername':
                $sql = "SELECT a.id, a.username, a.ally_id, a.galaxy, a.system, a.planet, b.name, c.total_rank, d.ally_name
				FROM %%USERS%% as a
				INNER JOIN %%PLANETS%% as b ON b.id = a.id_planet
				LEFT JOIN %%STATPOINTS%% as c ON c.id_owner = a.id AND c.stat_type = 1
				LEFT JOIN %%ALLIANCE%% as d ON d.id = a.ally_id
				WHERE a.universe = :universe AND a.username LIKE :searchTextLike
				ORDER BY (
				  IF(a.username = :searchText, 1, 0)
				  + IF(a.username LIKE :searchTextLike, 1, 0)
				) DESC, a.username ASC
				" . $limit . ";";
                $searchResult = $db->select($sql, [
                    ':universe'			=> $this->universe()->current(),
                    ':searchText' 		=> $searchText,
                    ':searchTextLike'	=> '%' . $searchText . '%',
                ]);

                foreach ($searchResult as $searchRow) {
                    $searchList[]	= [
                        'planetname'	=> $searchRow['name'],
                        'username' 		=> $searchRow['username'],
                        'userid' 		=> $searchRow['id'],
                        'allyname'	 	=> $searchRow['ally_name'],
                        'allyid'		=> $searchRow['ally_id'],
                        'galaxy' 		=> $searchRow['galaxy'],
                        'system'		=> $searchRow['system'],
                        'planet'		=> $searchRow['planet'],
                        'rank'			=> $searchRow['total_rank'],
                    ];
                }
                break;
            case 'planetname':

                $sql = "SELECT a.name, a.galaxy, a.planet, a.system,
				b.id, b.ally_id, b.username,
				c.total_rank,
				d.ally_name
				FROM %%PLANETS%% as a
				INNER JOIN %%USERS%% as b ON b.id = a.id_owner
				LEFT JOIN  %%STATPOINTS%% as c ON c.id_owner = b.id AND c.stat_type = 1
				LEFT JOIN %%ALLIANCE%% as d ON d.id = b.ally_id
				WHERE a.universe = :universe AND a.name LIKE :searchTextLike AND a.destruyed = 0
				ORDER BY (
				  IF(a.name = :searchText, 1, 0)
				  + IF(a.name LIKE :searchTextLike, 1, 0)
				) DESC, a.name ASC
				" . $limit . ";";

                $searchResult = $db->select($sql, [
                    ':universe'			=> $this->universe()->current(),
                    ':searchText' 		=> $searchText,
                    ':searchTextLike'	=> '%' . $searchText . '%',
                ]);

                foreach ($searchResult as $searchRow) {
                    $searchList[]	= [
                        'planetname'	=> $searchRow['name'],
                        'username' 		=> $searchRow['username'],
                        'userid' 		=> $searchRow['id'],
                        'allyname'	 	=> $searchRow['ally_name'],
                        'allyid'		=> $searchRow['ally_id'],
                        'galaxy' 		=> $searchRow['galaxy'],
                        'system'		=> $searchRow['system'],
                        'planet'		=> $searchRow['planet'],
                        'rank'			=> $searchRow['total_rank'],
                    ];
                }
                break;
            case "allytag":
                $sql = "SELECT a.id, a.ally_name, a.ally_tag, a.ally_members,
				c.total_points FROM %%ALLIANCE%% as a
				LEFT JOIN %%STATPOINTS%% as c ON c.stat_type = 2 AND c.id_owner = a.id
				WHERE a.ally_universe = :universe AND a.ally_tag LIKE :searchTextLike
				ORDER BY (
				  IF(a.ally_tag = :searchText, 1, 0)
				  + IF(a.ally_tag LIKE :searchTextLike, 1, 0)
				) DESC, a.ally_tag ASC
				" . $limit . ";";

                $searchResult = $db->select($sql, [
                    ':universe'			=> $this->universe()->current(),
                    ':searchText' 		=> $searchText,
                    ':searchTextLike'	=> '%' . $searchText . '%',
                ]);

                foreach ($searchResult as $searchRow) {
                    $searchList[]	= [
                        'allypoints'	=> $this->viewHelpers()->prettyNumber($searchRow['total_points']),
                        'allytag'		=> $searchRow['ally_tag'],
                        'allymembers'	=> $searchRow['ally_members'],
                        'allyname'		=> $searchRow['ally_name'],
                        'allyid'		=> $searchRow['id'],
                    ];
                }
                break;
            case "allyname":
                $sql = "SELECT a.id, a.ally_name, a.ally_tag, a.ally_members,
				b.total_points FROM %%ALLIANCE%% as a
				LEFT JOIN %%STATPOINTS%% as b ON b.stat_type = 2 AND b.id_owner = a.id
				WHERE a.ally_universe = :universe AND a.ally_name LIKE :searchTextLike
				ORDER BY (
				  IF(a.ally_name = :searchText, 1, 0)
				  + IF(a.ally_name LIKE :searchTextLike, 1, 0)
				) DESC,a.ally_name ASC
				" . $limit . ";";

                $searchResult = $db->select($sql, [
                    ':universe'			=> $this->universe()->current(),
                    ':searchText' 		=> $searchText,
                    ':searchTextLike'	=> '%' . $searchText . '%',
                ]);

                foreach ($searchResult as $searchRow) {
                    $searchList[]	= [
                        'allypoints'	=> $this->viewHelpers()->prettyNumber($searchRow['total_points']),
                        'allytag'		=> $searchRow['ally_tag'],
                        'allymembers'	=> $searchRow['ally_members'],
                        'allyname'		=> $searchRow['ally_name'],
                        'allyid'		=> $searchRow['id'],
                    ];
                }
                break;
        }

        return $searchList;
    }

    public function autocomplete()
    {
        $LNG = $this->state->LNG;

        $this->setWindow('ajax');

        $seachMode 	= $this->http()->_GP('type', 'playername');
        $searchText	= $this->http()->_GP('term', '', UTF8_SUPPORT);

        $searchList	= [];

        $seachModes	= explode('|', $seachMode);

        if (empty($searchText)) {
            $this->sendJSON([]);
        }

        foreach ($seachModes as $search) {
            $searchData	= $this->_getSearchList($search, $searchText, 5);
            foreach ($searchData as $data) {
                switch ($search) {
                    case 'playername':
                        $searchList[]	= ['label' => str_replace($searchText, '<b>' . $searchText . '</b>', $data['username']), 'category' => $LNG['sh_player_name'], 'type' => 'playername'];
                        break;
                    case 'planetname':
                        $searchList[]	= ['label' => str_replace($searchText, '<b>' . $searchText . '</b>', $data['planetname']), 'category' => $LNG['sh_planet_name'], 'type' => 'planetname'];
                        break;
                    case "allytag":
                        $searchList[]	= ['label' => str_replace($searchText, '<b>' . $searchText . '</b>', $data['allytag']), 'category' => $LNG['sh_alliance_tag'], 'type' => 'allytag'];
                        break;
                    case "allyname":
                        $searchList[]	= ['label' => str_replace($searchText, '<b>' . $searchText . '</b>', $data['allyname']), 'category' => $LNG['sh_alliance_name'], 'type' => 'allyname'];
                        break;
                }
            }
        }

        $this->sendJSON($searchList);
    }

    public function result(): Response
    {
        $THEME = $this->state->THEME;

        $this->setWindow('ajax');

        $seachMode 	= $this->http()->_GP('type', 'playername');
        $searchText	= $this->http()->_GP('search', '', UTF8_SUPPORT);

        $searchList	= [];

        if (!empty($searchText)) {
            $searchList	= $this->_getSearchList($seachMode, $searchText, SEARCH_LIMIT);
        }

        $this->assign([
            'searchList'	=> $searchList,
            'dpath'			=> $THEME->getTheme(),
            'themeCssPath' => $THEME->getThemeCssPath(),
        ]);

        $templateSuffix	= ($seachMode === "allyname" || $seachMode === "allytag") ? "ally" : "default";

        return $this->render('game/page.search.result.' . $templateSuffix . '.html.twig');
    }

    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $THEME = $this->state->THEME;
        $seachMode 		= $this->http()->_GP('type', 'playername');

        $modeSelector	= ['playername' => $LNG['sh_player_name'], 'planetname' => $LNG['sh_planet_name'], 'allytag' => $LNG['sh_alliance_tag'], 'allyname' => $LNG['sh_alliance_name']];
        $this->addScript('search.js');
        $this->assign([
            'modeSelector'	=> $modeSelector,
            'seachMode'		=> $seachMode,
        ]);

        return $this->render('game/page.search.default.html.twig');
    }
}
