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
 * Search for players, planets and alliances (form, result list).
 */
#[\App\Routing\GamePage('game', 'search')]
#[\App\Controller\RequireModule('MODULE_SEARCH')]
class SearchController extends AbstractGameController
{
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
            ->setParameter('searchTextLike', '%' . addcslashes($searchText, '%_\\') . '%')
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
        $universe = $this->universe()->current();

        switch ($searchMode) {
            case 'playername':
                return $this->searchPlayers($universe, $searchText, $maxResult);
            case 'planetname':
                return $this->searchPlanets($universe, $searchText, $maxResult);
            case "allytag":
                return $this->searchAlliances('a.ally_tag', 'c', $universe, $searchText, $maxResult);
            case "allyname":
                return $this->searchAlliances('a.ally_name', 'b', $universe, $searchText, $maxResult);
        }

        return [];
    }

    /**
     * Player search: users whose name matches, with home planet name and coordinates, alliance and total rank.
     *
     * @return list<array<string, mixed>> template rows (see _getSearchList())
     */
    private function searchPlayers(mixed $universe, string $searchText, int $maxResult): array
    {
        $db = $this->db;
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
            $maxResult,
        ));

        return $this->planetRows($searchResult);
    }

    /**
     * Planet search: non-destroyed planets and moons whose name matches, with owner, alliance and total rank.
     *
     * @return list<array<string, mixed>> template rows (see _getSearchList())
     */
    private function searchPlanets(mixed $universe, string $searchText, int $maxResult): array
    {
        $db = $this->db;
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
            $maxResult,
        ));

        return $this->planetRows($searchResult);
    }

    /**
     * Maps player/planet search rows to the template keys.
     *
     * @param list<array<string, mixed>> $searchResult rows of the player or planet query
     * @return list<array<string, mixed>>
     */
    private function planetRows(array $searchResult): array
    {
        $searchList = [];
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

        return $searchList;
    }

    /**
     * Alliance search by tag or name: alliances of the universe with member count and points.
     *
     * @param string $column fixed column expression to match (a.ally_tag or a.ally_name)
     * @param string $pointsAlias alias of the joined STATPOINTS table
     * @return list<array<string, mixed>> template rows (see _getSearchList())
     */
    private function searchAlliances(string $column, string $pointsAlias, mixed $universe, string $searchText, int $maxResult): array
    {
        $db = $this->db;
        $searchResult = $db->fetchAll($this->matchOrder(
            $db->qb()
                ->select('a.id', 'a.ally_name', 'a.ally_tag', 'a.ally_members', $pointsAlias . '.total_points')
                ->from($db->table('ALLIANCE'), 'a')
                ->leftJoin('a', $db->table('STATPOINTS'), $pointsAlias, $pointsAlias . '.stat_type = 2 AND ' . $pointsAlias . '.id_owner = a.id')
                ->where('a.ally_universe = :universe'),
            $column,
            $universe,
            $searchText,
            $maxResult,
        ));

        $searchList = [];
        foreach ($searchResult as $searchRow) {
            $searchList[]	= [
                'allypoints'	=> $this->viewHelpers()->prettyNumber($searchRow['total_points']),
                'allytag'		=> $searchRow['ally_tag'],
                'allymembers'	=> $searchRow['ally_members'],
                'allyname'		=> $searchRow['ally_name'],
                'allyid'		=> $searchRow['id'],
            ];
        }

        return $searchList;
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
        $modeSelector	= ['playername' => $LNG['sh_player_name'], 'planetname' => $LNG['sh_planet_name'], 'allytag' => $LNG['sh_alliance_tag'], 'allyname' => $LNG['sh_alliance_name']];
        $this->addScript('search.js');
        $this->assign([
            'modeSelector'	=> $modeSelector,
        ]);

        return $this->render('game/page.search.default.html.twig');
    }
}
