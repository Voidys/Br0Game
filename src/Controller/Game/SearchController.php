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

use Doctrine\DBAL\Query\QueryBuilder;
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

    /**
     * Completes a search query: the (code-chosen) column must contain the text, exact matches come first, then
     * alphabetical by that column. The text is bound as a `%text%` pattern; $maxResult -1 means no row limit.
     *
     * @param string $column fixed column expression (alias.column), never request input
     */
    private function matchOrder(QueryBuilder $qb, string $column, mixed $universe, string $searchText, int $maxResult): QueryBuilder
    {
        $qb->andWhere($column . ' LIKE :searchTextLike')
            ->setParameter('universe', $universe)
            ->setParameter('searchText', $searchText)
            ->setParameter('searchTextLike', '%' . $searchText . '%')
            ->orderBy('(IF(' . $column . ' = :searchText, 1, 0) + IF(' . $column . ' LIKE :searchTextLike, 1, 0))', 'DESC')
            ->addOrderBy($column, 'ASC');
        if ($maxResult !== -1) {
            $qb->setMaxResults($maxResult);
        }

        return $qb;
    }

    /**
     * Searches the current universe. $searchMode: playername (users, with home planet, alliance and total rank), planetname (non-destroyed planets/moons
     * with owner), allytag or allyname (alliances with member count and points). Matches are bound `%text%` LIKE patterns, exact matches first, then
     * alphabetical. $maxResult is the row limit (-1 = no limit). Returns rows with the keys the templates use (planetname, username, userid, allyname,
     * allyid, galaxy, system, planet, rank for players and planets; allypoints, allytag, allymembers, allyname, allyid for alliances); unknown modes give [].
     */
    private function _getSearchList($searchMode, $searchText, $maxResult)
    {
        $db = $this->db;
        $universe = $this->universe()->current();

        $searchList	= [];

        switch ($searchMode) {
            case 'playername':
                $searchResult = $db->fetchAll($this->matchOrder(
                    $db->qb()
                        ->select('a.id', 'a.username', 'a.ally_id', 'a.galaxy', 'a.`system`', 'a.planet', 'b.name', 'c.total_rank', 'd.ally_name')
                        ->from($db->table('USERS'), 'a')
                        ->innerJoin('a', $db->table('PLANETS'), 'b', 'b.id = a.id_planet')
                        ->leftJoin('a', $db->table('STATPOINTS'), 'c', 'c.id_owner = a.id AND c.stat_type = 1')
                        ->leftJoin('a', $db->table('ALLIANCE'), 'd', 'd.id = a.ally_id')
                        ->where('a.universe = :universe'),
                    'a.username',
                    $universe,
                    $searchText,
                    $maxResult
                ));

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

                $searchResult = $db->fetchAll($this->matchOrder(
                    $db->qb()
                        ->select('a.name', 'a.galaxy', 'a.planet', 'a.`system`', 'b.id', 'b.ally_id', 'b.username', 'c.total_rank', 'd.ally_name')
                        ->from($db->table('PLANETS'), 'a')
                        ->innerJoin('a', $db->table('USERS'), 'b', 'b.id = a.id_owner')
                        ->leftJoin('b', $db->table('STATPOINTS'), 'c', 'c.id_owner = b.id AND c.stat_type = 1')
                        ->leftJoin('b', $db->table('ALLIANCE'), 'd', 'd.id = b.ally_id')
                        ->where('a.universe = :universe')
                        ->andWhere('a.destruyed = 0'),
                    'a.name',
                    $universe,
                    $searchText,
                    $maxResult
                ));

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
                $searchResult = $db->fetchAll($this->matchOrder(
                    $db->qb()
                        ->select('a.id', 'a.ally_name', 'a.ally_tag', 'a.ally_members', 'c.total_points')
                        ->from($db->table('ALLIANCE'), 'a')
                        ->leftJoin('a', $db->table('STATPOINTS'), 'c', 'c.stat_type = 2 AND c.id_owner = a.id')
                        ->where('a.ally_universe = :universe'),
                    'a.ally_tag',
                    $universe,
                    $searchText,
                    $maxResult
                ));

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
                $searchResult = $db->fetchAll($this->matchOrder(
                    $db->qb()
                        ->select('a.id', 'a.ally_name', 'a.ally_tag', 'a.ally_members', 'b.total_points')
                        ->from($db->table('ALLIANCE'), 'a')
                        ->leftJoin('a', $db->table('STATPOINTS'), 'b', 'b.stat_type = 2 AND b.id_owner = a.id')
                        ->where('a.ally_universe = :universe'),
                    'a.ally_name',
                    $universe,
                    $searchText,
                    $maxResult
                ));

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

    /**
     * JSON suggestions (XHR). Request: type (one or several modes joined with |), term. Up to 5 hits per mode with the matching part
     * highlighted.
     */
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

    /**
     * Result list (ajax fragment). Request: type, search; at most SEARCH_LIMIT rows; alliance searches use the alliance template.
     */
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

    /**
     * Search form with the mode selector (request: type preselects the mode).
     */
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
