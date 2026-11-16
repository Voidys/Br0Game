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

namespace App\Controller\Admin;

use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Admin search over players, planets, alliances, messages and the like.
 */
#[\App\Routing\GamePage('admin.php', 'search')]
#[\App\Routing\GamePage('admin.php', 'search', uniPrefix: true)]
class SearchController extends AbstractAdminController
{
    /**
     * Admin search over players (users, banned), planets/moons and alliances. Request: search (type), search_in (name or id), fuki
     * (normal, exact, last, first: how the key is matched), key_user (search key), key_order (sort column from the whitelist), key_acc
     * (ASC/DESC), limit (page size from 1..500), side (page). Builds the field list and WHERE parts per type and delegates to
     * MyCrazyLittleSearch().
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $this->handleDelete();

        $SearchFile = $this->http()->_GP('search', '');
        $SearchFor = $this->http()->_GP('search_in', '');
        $SearchMethod = $this->http()->_GP('fuki', '');
        $SearchKey = $this->http()->_GP('key_user', '', UTF8_SUPPORT);
        $Page = $this->http()->_GP('side', 0);
        $Order = $this->http()->_GP('key_order', '');
        $OrderBY = $this->http()->_GP('key_acc', '');
        // page size: only the values offered in the form (an empty or foreign value meant LIMIT x,0 and a division by zero)
        $limit = $this->http()->_GP('limit', 25);
        if (!in_array($limit, [1, 5, 10, 15, 20, 25, 50, 100, 200, 500], true)) {
            $limit = 25;
        }

        $Selector = $this->selectorOptions();
        [$SpecifyWhere, $SpecifySearchKey] = $this->matchCondition($SearchMethod, $SearchKey);

        // only the known search lists (e.g. "messages" has no search of its own)
        $knownSearchFiles = ["users", "vacation", "admin", "inactives", "online", "planet", "moon", "p_connect", "banned", "alliance"];
        if (!empty($SearchFile) && in_array($SearchFile, $knownSearchFiles, true)) {
            $spec = $this->searchSpec($SearchFile, $SearchFor);
            $OrderBYParse = $spec['orderParse'];

            $RESULT = $this->MyCrazyLittleSearch(
                $spec['items'],
                $spec['where'],
                $SpecifyWhere,
                $SpecifySearchKey,
                $spec['special'],
                $Order,
                $OrderBY,
                $limit,
                $spec['table'],
                $Page,
                $spec['nameLang'],
                $spec['sec'],
                $spec['name'],
                $SearchFile,
            );
        }
        if (!isset($OrderBYParse)) {
            $OrderBYParse = '';
        }
        $RESULT ??= null;
        $this->assign([
            'Selector'             => $Selector,
            'limit'                => $limit,
            'search'               => $SearchKey,
            'SearchFile'           => $SearchFile,
            'SearchFor'            => $SearchFor,
            'SearchMethod'         => $SearchMethod,
            'Order'                => $Order,
            'OrderBY'              => $OrderBY,
            'OrderBYParse'         => $OrderBYParse,
            'se_search'            => $LNG['se_search'],
            'se_limit'             => $LNG['se_limit'],
            'se_asc_desc'          => $LNG['se_asc_desc'],
            'se_filter_title'      => $LNG['se_filter_title'],
            'se_search_in'         => $LNG['se_search_in'],
            'se_type_typee'        => $LNG['se_type_typee'],
            'se_intro'             => $LNG['se_intro'],
            'se_search_title'      => $LNG['se_search_title'],
            'se_contrac'           => $LNG['se_contrac'],
            'se_search_order'      => $LNG['se_search_order'],
            'ac_minimize_maximize' => $LNG['ac_minimize_maximize'],
            'result'               => $RESULT,
        ]);

        return $this->render('admin/SearchPage.html.twig');
    }

    /**
     * Handles the delete links of the result list (`delete=user&user=<id>` / `delete=planet&planet=<id>`): deletes the
     * player or planet and ends the request with a message. An unknown player, an unknown planet or a main planet
     * (not deletable) ends with the "not found" message instead.
     */
    private function handleDelete(): void
    {
        $LNG = $this->state->LNG;
        if (!isset($this->state->requestQuery['delete'])) {
            $this->state->requestQuery['delete'] = '';
        }
        if ($this->state->requestQuery['delete'] == 'user') {
            if (!$this->playerUtil()->deletePlayer((int) $this->state->requestQuery['user'])) {
                $this->message($LNG['se_delete_not_found'], '?page=search&search=users&minimize=on', 2);
            }
            $this->message($LNG['se_delete_succes_p'], '?page=search&search=users&minimize=on', 2);
        } elseif ($this->state->requestQuery['delete'] == 'planet') {
            $planetId = (int) $this->state->requestQuery['planet'];
            // the same condition deletePlanet() applies (it throws for unknown planets and main planets)
            $deletable = $this->db->selectSingle(
                'SELECT `id` FROM %%PLANETS%% WHERE `id` = :planetId AND `id` NOT IN (SELECT `id_planet` FROM %%USERS%%);',
                [':planetId' => $planetId],
            );
            if (empty($deletable)) {
                $this->message($LNG['se_delete_not_found'], '?page=search&search=planet&minimize=on', 2);
            }
            $this->playerUtil()->deletePlanet($planetId);
            $this->message($LNG['se_delete_succes_p'], '?page=search&search=planet&minimize=on', 2);
        }
    }

    /**
     * Option lists of the search form (list types, search in, match type, direction, page sizes), translated.
     */
    private function selectorOptions(): array
    {
        $LNG = $this->state->LNG;

        return [
            'list'   => [
                'users'     => $LNG['se_users'],
                'planet'    => $LNG['se_planets'],
                'moon'      => $LNG['se_moons'],
                'alliance'  => $LNG['se_allys'],
                'vacation'  => $LNG['se_vacations'],
                'banned'    => $LNG['se_suspended'],
                'admin'     => $LNG['se_authlevels'],
                'inactives' => $LNG['se_inactives'],
                'online'    => $LNG['online_users'],
                'p_connect' => $LNG['se_planets_act'],
            ],
            'search' => [
                'name' => $LNG['se_input_name'],
                'id'   => $LNG['input_id'],
            ],
            'filter' => [
                'normal' => $LNG['se_type_all'],
                'exacto' => $LNG['se_type_exact'],
                'last'   => $LNG['se_type_last'],
                'first'  => $LNG['se_type_first'],
            ],
            'order'  => [
                'ASC'  => $LNG['se_input_asc'],
                'DESC' => $LNG['se_input_desc'],
            ],
            'limit'  => [
                '1'   => '1',
                '5'   => '5',
                '10'  => '10',
                '15'  => '15',
                '20'  => '20',
                '25'  => '25',
                '50'  => '50',
                '100' => '100',
                '200' => '200',
                '500' => '500',
            ],
        ];
    }

    /**
     * Turns the match type into the SQL comparison and the search key to bind (LIKE patterns get their % wildcards).
     *
     * @return array{0: string, 1: string} comparison ("= :searchKey" or "LIKE :searchKey") and the bound value
     */
    private function matchCondition(mixed $SearchMethod, mixed $SearchKey): array
    {
        $SpecifySearchKey = $SearchKey;

        switch ($SearchMethod) {
            case 'exacto':
                $SpecifyWhere = "= :searchKey";
                break;
            case 'last':
                $SpecifyWhere = "LIKE :searchKey";
                $SpecifySearchKey .= '%';
                break;
            case 'first':
                $SpecifyWhere = "LIKE :searchKey";
                $SpecifySearchKey = '%' . $SearchKey;
                break;
            default:
                $SpecifyWhere = "LIKE :searchKey";
                $SpecifySearchKey = '%' . $SearchKey . '%';
                break;
        };

        return [$SpecifyWhere, $SpecifySearchKey];
    }

    /**
     * Picks the search definition of the chosen list (players, planets/moons, bans or alliances).
     *
     * @return array see userSearchSpec()
     */
    private function searchSpec(string $SearchFile, mixed $SearchFor): array
    {
        if (in_array($SearchFile, ["users", "vacation", "admin", "inactives", "online"])) {
            return $this->userSearchSpec($SearchFile, $SearchFor);
        }
        if (in_array($SearchFile, ["planet", "moon", "p_connect"])) {
            return $this->planetSearchSpec($SearchFile, $SearchFor);
        }
        if (in_array($SearchFile, ["banned"])) {
            return $this->bannedSearchSpec($SearchFile, $SearchFor);
        }
        return $this->allianceSearchSpec($SearchFile, $SearchFor);
    }

    /**
     * Search definition of the player lists (users, vacation, online, inactives, admin): table, column labels, select items, extra conditions (list filter and emulated universe) and the searched column.
     *
     * @return array{table: string, nameLang: array, items: array, name: string, special: array, where: string, sec: array, orderParse: array}
     */
    private function userSearchSpec(string $SearchFile, mixed $SearchFor): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $SpecialSpecify = [];
        $Table = "USERS";
        $NameLang = [
            0 => $LNG['se_search_users_0'],
            1 => $LNG['se_search_users_1'],
            2 => $LNG['se_search_users_3'],
            3 => $LNG['se_search_users_4'],
            4 => $LNG['se_search_users_5'],
            5 => $LNG['se_search_users_6'],
            6 => $LNG['se_search_users_7'],
            7 => $LNG['se_search_users_8'],
        ];
        $SpecifyItems = ["id", "username", "onlinetime", "register_time", "user_lastip", "authlevel", "bana", "urlaubs_modus"];
        $SName = $LNG['se_input_userss'];
        if ($SearchFile == "vacation") {
            $SpecialSpecify[] = ['urlaubs_modus', '=', 1];
            $SName = $LNG['se_input_vacatii'];
        }

        if ($SearchFile == "online") {
            $SpecialSpecify[] = ['onlinetime', '>=', $this->state->runtime->timestamp - 15 * 60];
            $SName = $LNG['se_input_connect'];
        }

        if ($SearchFile == "inactives") {
            $SpecialSpecify[] = ['onlinetime', '<', $this->state->runtime->timestamp - 60 * 60 * 24 * 7];
            $SName = $LNG['se_input_inact'];
        }

        if ($SearchFile == "admin") {
            $SpecialSpecify[] = ['authlevel', '<=', $USER['authlevel']];
            $SpecialSpecify[] = ['authlevel', '>', 0];
            $SName = $LNG['se_input_admm'];
        }

        $SpecialSpecify[] = ['universe', '=', $this->universe()->getEmulated()];

        $WhereItem = $SearchFor == "name" ? "username" : "id";
        $ArrayOSec = [
            "id",
            "username",
            "onlinetime",
            "register_time",
            "user_lastip",
            "authlevel",
            "bana",
            "urlaubs_modus",
        ];
        $Array0SecCount = count($ArrayOSec);

        for ($OrderNum = 0; $OrderNum < $Array0SecCount; $OrderNum++) {
            $OrderBYParse[$ArrayOSec[$OrderNum]] = $LNG['se_search_users_' . $OrderNum];
        }
        return [
            'table' => $Table,
            'nameLang' => $NameLang,
            'items' => $SpecifyItems,
            'name' => $SName,
            'special' => $SpecialSpecify,
            'where' => $WhereItem,
            'sec' => $ArrayOSec,
            'orderParse' => $OrderBYParse,
        ];
    }

    /**
     * Search definition of the planet lists (planet, moon, p_connect): PLANETS joined with the owner; see userSearchSpec().
     *
     * @return array{table: string, nameLang: array, items: array, name: string, special: array, where: string, sec: array, orderParse: array}
     */
    private function planetSearchSpec(string $SearchFile, mixed $SearchFor): array
    {
        $LNG = $this->state->LNG;
        $SpecialSpecify = [];
        $Table = "PLANETS";
        $NameLang = [
            0 => $LNG['se_search_planets_0'],
            1 => $LNG['se_search_planets_1'],
            2 => $LNG['se_search_planets_2'],
            3 => $LNG['se_search_planets_3'],
            4 => $LNG['se_search_planets_4'],
            5 => $LNG['se_search_planets_5'],
            6 => $LNG['se_search_planets_6'],
            7 => $LNG['se_search_planets_7'],
        ];
        $SpecifyItems = [
            "p.id", "p.name", "CONCAT(u.username, ' (ID:&nbsp;', p.id_owner, ')') AS owner", "p.last_update",
            "p.galaxy", "p.`system`", "p.planet", "p.id_luna",
        ];

        if ($SearchFile == "planet") {
            $SpecialSpecify[] = ['p.planet_type', '=', 1];
            $SName = $LNG['se_input_planett'];
        } elseif ($SearchFile == "moon") {
            $SpecialSpecify[] = ['p.planet_type', '=', 3];
            $SName = $LNG['se_input_moonn'];
        } elseif ($SearchFile == "p_connect") {
            $SpecialSpecify[] = ['p.last_update', '>=', $this->state->runtime->timestamp - 60 * 60];
            $SName = $LNG['se_input_act_pla'];
        }

        $SpecialSpecify[] = ['p.universe', '=', $this->universe()->getEmulated()];
        $WhereItem = $SearchFor == "name" ? "p.name" : "p.id";

        $ArrayOSec = ["id", "name", "id_owner", "id_luna", "last_update", "galaxy", "system", "planet"];
        $Array0SecCount = count($ArrayOSec);

        for ($OrderNum = 0; $OrderNum < $Array0SecCount; $OrderNum++) {
            $OrderBYParse[$ArrayOSec[$OrderNum]] = $LNG['se_search_planets_' . $OrderNum];
        }
        return [
            'table' => $Table,
            'nameLang' => $NameLang,
            'items' => $SpecifyItems,
            'name' => $SName,
            'special' => $SpecialSpecify,
            'where' => $WhereItem,
            'sec' => $ArrayOSec,
            'orderParse' => $OrderBYParse,
        ];
    }

    /**
     * Search definition of the ban list (USERS_BANNED); see userSearchSpec().
     *
     * @return array{table: string, nameLang: array, items: array, name: string, special: array, where: string, sec: array, orderParse: array}
     */
    private function bannedSearchSpec(string $SearchFile, mixed $SearchFor): array
    {
        $LNG = $this->state->LNG;
        $SpecialSpecify = [];
        $Table = "USERS_BANNED";
        $NameLang = [
            0 => $LNG['se_search_banned_0'],
            1 => $LNG['se_search_banned_1'],
            2 => $LNG['se_search_banned_2'],
            3 => $LNG['se_search_banned_3'],
            4 => $LNG['se_search_banned_4'],
            5 => $LNG['se_search_banned_5'],
        ];
        $SpecifyItems = ["id", "who", "time", "longer", "theme", "author"];
        $SName = $LNG['se_input_susss'];
        $SpecialSpecify[] = ['universe', '=', $this->universe()->getEmulated()];

        $WhereItem = $SearchFor == "name" ? "who" : "id";

        $ArrayOSec = ["id", "who", "time", "longer", "theme", "author"];
        $Array0SecCount = count($ArrayOSec);

        for ($OrderNum = 0; $OrderNum < $Array0SecCount; $OrderNum++) {
            $OrderBYParse[$ArrayOSec[$OrderNum]] = $LNG['se_search_banned_' . $OrderNum];
        }
        return [
            'table' => $Table,
            'nameLang' => $NameLang,
            'items' => $SpecifyItems,
            'name' => $SName,
            'special' => $SpecialSpecify,
            'where' => $WhereItem,
            'sec' => $ArrayOSec,
            'orderParse' => $OrderBYParse,
        ];
    }

    /**
     * Search definition of the alliance list (ALLIANCE); see userSearchSpec().
     *
     * @return array{table: string, nameLang: array, items: array, name: string, special: array, where: string, sec: array, orderParse: array}
     */
    private function allianceSearchSpec(string $SearchFile, mixed $SearchFor): array
    {
        $LNG = $this->state->LNG;
        $SpecialSpecify = [];
        $Table = "ALLIANCE";
        $NameLang = [
            0 => $LNG['se_search_alliance_0'],
            1 => $LNG['se_search_alliance_1'],
            2 => $LNG['se_search_alliance_2'],
            3 => $LNG['se_search_alliance_3'],
            4 => $LNG['se_search_alliance_4'],
            5 => $LNG['se_search_alliance_5'],
        ];
        $SpecifyItems = ["id", "ally_name", "ally_tag", "ally_owner", "ally_register_time", "ally_members"];
        $SName = $LNG['se_input_allyy'];
        $SpecialSpecify[] = ['ally_universe', '=', $this->universe()->getEmulated()];

        $WhereItem = $SearchFor == "name" ? "ally_name" : "id";

        $ArrayOSec = ["id", "ally_name", "ally_tag", "ally_owner", "ally_register_time", "ally_members"];
        $Array0SecCount = count($ArrayOSec);

        for ($OrderNum = 0; $OrderNum < $Array0SecCount; $OrderNum++) {
            $OrderBYParse[$ArrayOSec[$OrderNum]] = $LNG['se_search_alliance_' . $OrderNum];
        }
        return [
            'table' => $Table,
            'nameLang' => $NameLang,
            'items' => $SpecifyItems,
            'name' => $SName,
            'special' => $SpecialSpecify,
            'where' => $WhereItem,
            'sec' => $ArrayOSec,
            'orderParse' => $OrderBYParse,
        ];
    }
    /**
     * Runs the search query and the matching count query (QueryBuilder). Callers pass trusted pieces: the game table key
     * $Table (USERS, PLANETS, USERS_BANNED, ALLIANCE; PLANETS is aliased "p" and joined with its owner "u"), the list of
     * $SpecifyItems select expressions, the $WhereItem column the search key is compared with ($SpecifyWhere is "= :searchKey" or
     * "LIKE :searchKey"), and the extra conditions $SpecialSpecify as [column, operator, value] (values are bound). The search
     * key is a bound parameter, the sort column must be in $ArrayOSec (unknown ones fall back to the first column) and the
     * direction is limited to ASC/DESC. Computes paging from $Page and $Limit and fills the result table (column names and
     * links) for the template.
     */
    public function MyCrazyLittleSearch(
        array $SpecifyItems,
        string $WhereItem,
        string $SpecifyWhere,
        $SpecifySearchKey,
        array $SpecialSpecify,
        $Order,
        $OrderBY,
        $Limit,
        string $Table,
        $Page,
        $NameLang,
        $ArrayOSec,
        $SName,
        $SearchFile,
    ) {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        if (!$Page) {
            $INI = 0;
            $Page = 1;
        } else {
            $INI = ($Page - 1) * $Limit;
        }

        if (!$Order || !in_array($Order, $ArrayOSec)) {
            $Order = $SpecifyItems[0];
        }

        $CountArray = count($SpecifyItems);

        $filtered = $this->searchFilter($Table, $WhereItem, $SpecifyWhere, $SpecifySearchKey, $SpecialSpecify);

        // the sort column is whitelisted (quoted: system); the direction comes from the request, only ASC/DESC may reach the query
        $orderColumn = implode('.', array_map(static fn($part) => '`' . $part . '`', explode('.', (string) $Order)));
        $sqlResult = $db->fetchAll(
            $filtered($db->qb()->select(...$SpecifyItems))
                ->orderBy($orderColumn, strtoupper((string) $OrderBY) === 'DESC' ? 'DESC' : 'ASC')
                ->setFirstResult($INI)
                ->setMaxResults($Limit)
        );
        $CountQuery = $db->fetchRow($filtered($db->qb()->select('COUNT(' . $SpecifyItems[0] . ') AS total')));

        if ($CountQuery['total'] <= 0) {
            return ['empty' => true];
        }

        $NumberOfPages = (int) ceil($CountQuery['total'] / $Limit);
        $UrlForPage = $this->searchPageUrl($SearchFile, $Limit);

        $columns = array_slice($NameLang, 0, $CountArray);
        $canInfo = $Table === 'USERS' && $this->viewHelpers()->allowedTo('ShowQuickEditorPage');
        $canEditPlanet = $Table === 'PLANETS' && $this->viewHelpers()->allowedTo('ShowQuickEditorPage');
        $isAdmin = $USER['authlevel'] == AUTH_ADM;

        $rows = [];
        foreach ($sqlResult as $WhileResult) {
            $WhileResult = $this->formatSearchRow($Table, $WhileResult);
            $actions = $this->searchRowActions($Table, $WhileResult, $canInfo, $canEditPlanet, $isAdmin);
            $rows[] = ['cells' => array_values($WhileResult), 'actions' => $actions];
        }

        return [
            'empty' => false,
            'columns' => $columns,
            'rows' => $rows,
            'hasActions' => $canInfo || $canEditPlanet || ($isAdmin && in_array($Table, ['USERS', 'PLANETS'], true)),
            'total' => (int) $CountQuery['total'],
            'name' => $SName,
            'page' => (int) $Page,
            'pages' => $NumberOfPages,
            'pageUrl' => $UrlForPage . '&side=',
        ];
    }

    /**
     * Builds the filter shared by the page query and the count query: the table (planets joined with their owner for
     * the name), the search condition with the bound key and the extra conditions (values bound).
     *
     * @return \Closure(QueryBuilder): QueryBuilder
     */
    private function searchFilter(string $Table, string $WhereItem, string $SpecifyWhere, mixed $SpecifySearchKey, array $SpecialSpecify): \Closure
    {
        $db = $this->db;

        // the same filter for the page and for the count: planets are listed with their owner's name
        return function (QueryBuilder $qb) use ($db, $Table, $WhereItem, $SpecifyWhere, $SpecifySearchKey, $SpecialSpecify): QueryBuilder {
            if ($Table === 'PLANETS') {
                $qb->from($db->table('PLANETS'), 'p')
                    ->leftJoin('p', $db->table('USERS'), 'u', 'u.id = p.id_owner');
            } else {
                $qb->from($db->table($Table));
            }
            $qb->where($WhereItem . ' ' . $SpecifyWhere)->setParameter('searchKey', $SpecifySearchKey);
            foreach ($SpecialSpecify as $i => [$column, $operator, $value]) {
                $qb->andWhere($column . ' ' . $operator . ' :special' . $i)->setParameter('special' . $i, $value);
            }

            return $qb;
        };
    }

    /**
     * Base URL of the result pages (the page number is appended); keeps the current search (the filter field is
     * "fucki", see the search form).
     */
    private function searchPageUrl(mixed $SearchFile, mixed $Limit): string
    {
        $input = $this->state->requestInput;
        return '?' . http_build_query([
            'page' => 'search',
            'search' => $SearchFile,
            'search_in' => $input['search_in'] ?? '',
            'fucki' => $input['fucki'] ?? '',
            'key_user' => $input['key_user'] ?? '',
            'key_order' => $input['key_order'] ?? '',
            'key_acc' => $input['key_acc'] ?? '',
            'limit' => $Limit,
        ]);
    }

    /**
     * Formats one result row for display: dates, relative times, rank names and yes/no badges depending on the table
     * (USERS, USERS_BANNED, ALLIANCE, PLANETS). Column order is kept (the template shows the cells in order).
     */
    private function formatSearchRow(string $Table, array $WhileResult): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $yes = '<span class="badge badge--ok">' . $LNG['one_is_yes_1'] . '</span>';
        $no = '<span class="muted">' . $LNG['one_is_yes_0'] . '</span>';

    if ($Table === 'USERS') {
        if (isset($this->state->requestQuery['search']) && $this->state->requestQuery['search'] == 'online') {
            $WhileResult['onlinetime'] = $this->viewHelpers()->prettyTime($this->state->runtime->timestamp - $WhileResult['onlinetime']);
        } else {
            $WhileResult['onlinetime'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $WhileResult['onlinetime'], $USER['timezone']);
        }
        $WhileResult['register_time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $WhileResult['register_time'], $USER['timezone']);
        $WhileResult['authlevel'] = $LNG['se_rank'][$WhileResult['authlevel']];
        $WhileResult['bana'] = $WhileResult['bana'] == '1' ? $yes : $no;
        $WhileResult['urlaubs_modus'] = $WhileResult['urlaubs_modus'] == '1' ? $yes : $no;
    }

    if ($Table === 'USERS_BANNED') {
        $WhileResult['time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $WhileResult['time'], $USER['timezone']);
        $WhileResult['longer'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $WhileResult['longer'], $USER['timezone']);
    }

    if ($Table === 'ALLIANCE') {
        $WhileResult['ally_register_time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $WhileResult['ally_register_time'], $USER['timezone']);
    }

    if ($Table === 'PLANETS') {
        $WhileResult['last_update'] = $this->viewHelpers()->prettyTime($this->state->runtime->timestamp - $WhileResult['last_update']);
        $WhileResult['id_luna'] = $WhileResult['id_luna'] > 0 ? $yes : $no;
    }

        return $WhileResult;
    }

    /**
     * Action links of one result row: edit (quick editor) for players/planets the admin may edit and delete links for
     * players (not self, not the root user) and planets (ADM only).
     */
    private function searchRowActions(string $Table, array $WhileResult, bool $canInfo, bool $canEditPlanet, bool $isAdmin): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;

    $actions = [];
    if ($canInfo) {
        $actions[] = ['type' => 'edit', 'edit' => 'player', 'id' => $WhileResult['id'], 'label' => $LNG['se_search_edit']];
    }
    if ($Table === 'USERS' && $isAdmin && $WhileResult['id'] != $USER['id'] && $WhileResult['id'] != ROOT_USER) {
        $actions[] = [
            'type' => 'delete',
            'href' => '?page=search&delete=user&user=' . $WhileResult['id'],
            'confirm' => $LNG['ul_sure_you_want_dlte'] . ' ' . $WhileResult['username'] . '?',
            'label' => $LNG['button_delete'],
        ];
    }
    if ($canEditPlanet) {
        $actions[] = ['type' => 'edit', 'edit' => 'planet', 'id' => $WhileResult['id'], 'label' => $LNG['se_search_edit']];
    }
    if ($Table === 'PLANETS' && $isAdmin) {
        $actions[] = [
            'type' => 'delete',
            'href' => '?page=search&delete=planet&planet=' . $WhileResult['id'],
            'confirm' => $LNG['se_confirm_planet'] . ' ' . $WhileResult['name'],
            'label' => $LNG['button_delete'],
        ];
    }

        return $actions;
    }
}
