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

use App\Game\Enum\PlanetType;
use App\Routing\GameRoute;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;
use App\Twig\GameEscaping;
use Twig\Markup;

/** Read-only overview of one player: account, planets, resources, buildings, fleet and defense. */
class AccountDataController extends AbstractAdminController
{
    /**
     * Read-only player report (admin/accounts). Without id_u a list of the users the acting admin may see (authlevel <= own)
     * to pick from; with id_u the detail page: account and login data, last IP, ban state and reason, points and ranks, alliance (with
     * owner and alliance stats), and per planet/moon the coordinates, fields, resources and the building, fleet and defense matrix
     * plus the player's research levels. Unknown ids end in a message. Writes nothing.
     */
    #[GameRoute('admin', 'admin/accounts', 'accountData')]
    public function show(): Response
    {
        $id_u = $this->input()->int('id_u');
        if (!empty($id_u)) {
            return $this->detail();
        }
        return $this->showUserPicker();
    }

    /** Detail page of the player `id_u` (also reached from the picker form as `admin/accounts?id_u=`). */
    #[GameRoute('admin', 'admin/accounts/{id_u}', 'accountData', 'detail', requirements: ['id_u' => '\d+'])]
    public function detail(): Response
    {
        return $this->showDetail($this->input()->int('id_u'));
    }

    /**
     * Detail page of one player: collects the template variables of the sections (account, ban, statistics, alliance,
     * planets) in the order the queries always ran and renders them. An unknown id ends in a message.
     */
    private function showDetail(mixed $id_u): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        // like the picker: players of the emulated universe up to the acting admin's own level
        $sql = "SELECT `id`, `authlevel` FROM %%USERS%% WHERE `id` = :userID AND `universe` = :universe AND `authlevel` <= :userLevel;";
        $OnlyQueryLogin = $db->selectSingle($sql, [
            ':userID' => $id_u,
            ':universe' => $this->universe()->getEmulated(),
            ':userLevel' => $this->state->USER['authlevel'],
        ]);
        if (empty($OnlyQueryLogin)) {
            return $this->messageResponse($LNG['ac_username_doesnt'], $this->router->generate('admin_account_data_show'));
        }

        $UserQuery = $this->loadPlayer($id_u);
        $accountVars = $this->accountVars($UserQuery);
        $banVars = $this->banVars($UserQuery);
        $statVars = $this->statVars($id_u);
        $allianceVars = $this->allianceVars($UserQuery);
        $planetVars = $this->planetVars($id_u);

        return $this->render('admin/AccountDataPageDetail.html.twig', $planetVars + $allianceVars + $statVars + $accountVars + $banVars + [
            'canedit'   => $this->viewHelpers()->allowedTo('ShowQuickEditorPage'),
            'ipcheck'   => $LNG['one_is_yes_1'],
            'Metal'     => $LNG['tech'][901],
            'Crystal'   => $LNG['tech'][902],
            'Deuterium' => $LNG['tech'][903],
            'Energy'    => $LNG['tech'][911],
        ]);
    }

    /**
     * Loads the player with account name, location, login/ban/alliance columns and the research levels (columns taken
     * from the game's resource table, not from the request).
     *
     * @return array<mixed>
     */
    private function loadPlayer(mixed $id_u): array
    {
        $e = $this->elements();
        $db = $this->db;
        // the research columns come from the game's resource table, not from the request
        $userColumns = [
            'ac.`id` AS account_id', 'ac.`accountname`', 'u.`id`', 'u.`username`', 'u.`authlevel`', 'u.`id_planet`',
            'u.`galaxy`', 'u.`system`', 'u.`planet`', 'u.`user_lastip`', 'u.`ip_at_reg`', 'u.`register_time`',
            'u.`onlinetime`', 'u.`urlaubs_modus`', 'u.`urlaubs_until`', 'u.`ally_id`', 'u.`ally_register_time`',
            'u.`ally_rank_id`', 'u.`bana`', 'u.`banaday`',
        ];
        foreach ($e->ids('tech') as $ID) {
            $userColumns[] = 'u.`' . $e->column($ID) . '`';
        }
        $UserQuery = $db->fetchRow(
            $db->qb()
                ->select(...$userColumns)
                ->from($db->table('USERS'), 'u')
                ->join('u', $db->table('ACCOUNTS_TO_USERS'), 'au', 'au.`user_id` = u.`id`')
                ->join('au', $db->table('ACCOUNTS'), 'ac', 'ac.`id` = au.`account_id`')
                ->where('u.`id` = :userID')
                ->setParameter('userID', $id_u),
        );

        if (!isset($UserQuery['user_ua'])) {
            $UserQuery['user_ua'] = '';
        }


        return $UserQuery;
    }

    /**
     * Template variables of the account block: names, ids, IPs, location, rank, dates, vacation/ban yes-no texts and
     * the research table (`technologyRows`).
     *
     * @param array<mixed> $UserQuery
     * @return array<mixed>
     */
    private function accountVars(array $UserQuery): array
    {
        $e = $this->elements();
        $LNG = $this->state->LNG;
        $reg_time = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $UserQuery['register_time'], $this->state->USER['timezone']);
        $onlinetime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $UserQuery['onlinetime'], $this->state->USER['timezone']);
        $account_id = $UserQuery['account_id'];
        $accountname = $UserQuery['accountname'];
        $id = $UserQuery['id'];
        $nombre = $UserQuery['username'];
        $ip = $UserQuery['ip_at_reg'];
        $ip2 = $UserQuery['user_lastip'];
        $id_p = $UserQuery['id_planet'];
        $g = $UserQuery['galaxy'];
        $s = $UserQuery['system'];
        $p = $UserQuery['planet'];
        $info = $UserQuery['user_ua'];
        $nivel = $LNG['se_rank'][$UserQuery['authlevel']];
        $vacas = $LNG['one_is_yes_' . $UserQuery['urlaubs_modus']];
        $suspen = $LNG['one_is_yes_' . $UserQuery['bana']];

        $technologyRows = [];
        foreach ($e->ids('tech') as $ID) {
            $technologyRows[] = [
                'name'  => $LNG['tech'][$ID],
                'level' => $UserQuery[$e->column($ID)],
            ];
        }

        return [
            'technologyRows' => $technologyRows,
            'account_id'     => $account_id,
            'accountname'    => $accountname,
            'id'             => $id,
            'nombre'         => $nombre,
            'nivel'          => $nivel,
            'vacas'          => $vacas,
            'suspen'         => $suspen,
            'ip'             => $ip,
            'ip2'            => $ip2,
            'reg_time'       => $reg_time,
            'onlinetime'     => $onlinetime,
            'id_p'           => $id_p,
            'g'              => $g,
            's'              => $s,
            'p'              => $p,
            'info'           => $info,
        ];
    }

    /**
     * Ban block: when the player is banned, the end, start, reason and author of the ban (reads USERS_BANNED).
     *
     * @param array<mixed> $UserQuery
     * @return array<mixed>
     */
    private function banVars(array $UserQuery): array
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $id = $UserQuery['id'];
        $mas = 0;
        $sus_time = 0;
        $sus_longer = 0;
        $sus_reason = '';
        $sus_author = 0;
        if ($UserQuery['bana'] != 0) {
            $mas = 1;

            $sql = "SELECT `theme`, `time`, `longer`, `author` FROM %%USERS_BANNED%%
            WHERE `user_id` = :user_id;";
            $BannedQuery = $db->selectSingle($sql, [
                ':user_id' => $id,
            ]);

            $sus_longer = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BannedQuery['longer'], $this->state->USER['timezone']);
            $sus_time = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BannedQuery['time'], $this->state->USER['timezone']);
            $sus_reason = $BannedQuery['theme'];
            $sus_author = $BannedQuery['author'];
        }

        return [
            'mas'        => $mas,
            'sus_time'   => $sus_time,
            'sus_longer' => $sus_longer,
            'sus_reason' => $sus_reason,
            'sus_author' => $sus_author,
        ];
    }

    /**
     * Points, counts and ranks of the player (STATPOINTS type 1), number-formatted.
     *
     * @return array<mixed>
     */
    private function statVars(mixed $id_u): array
    {
        $db = $this->db;
        // Stats data
        $sql = "SELECT `tech_count`, `defs_count`, `fleet_count`, `build_count`, `build_points`, `tech_points`,
        `defs_points`, `fleet_points`, `tech_rank`, `build_rank`, `defs_rank`, `fleet_rank`, `total_points`,
        `stat_type`
        FROM %%STATPOINTS%% WHERE `id_owner` = :userID AND `stat_type` = 1";
        $StatQuery = $db->selectSingle($sql, [
            ':userID' => $id_u,
        ]);

        $count_tecno = $this->viewHelpers()->prettyNumber($StatQuery['tech_count']);
        $count_def = $this->viewHelpers()->prettyNumber($StatQuery['defs_count']);
        $count_fleet = $this->viewHelpers()->prettyNumber($StatQuery['fleet_count']);
        $count_builds = $this->viewHelpers()->prettyNumber($StatQuery['build_count']);

        $point_builds = $this->viewHelpers()->prettyNumber($StatQuery['build_points']);
        $point_tecno = $this->viewHelpers()->prettyNumber($StatQuery['tech_points']);
        $point_def = $this->viewHelpers()->prettyNumber($StatQuery['defs_points']);
        $point_fleet = $this->viewHelpers()->prettyNumber($StatQuery['fleet_points']);

        $ranking_tecno = $StatQuery['tech_rank'];
        $ranking_builds = $StatQuery['build_rank'];
        $ranking_def = $StatQuery['defs_rank'];
        $ranking_fleet = $StatQuery['fleet_rank'];

        $total_points = $this->viewHelpers()->prettyNumber($StatQuery['total_points']);

        return [
            'point_tecno'    => $point_tecno,
            'count_tecno'    => $count_tecno,
            'ranking_tecno'  => $ranking_tecno,
            'point_def'      => $point_def,
            'count_def'      => $count_def,
            'ranking_def'    => $ranking_def,
            'point_fleet'    => $point_fleet,
            'count_fleet'    => $count_fleet,
            'ranking_fleet'  => $ranking_fleet,
            'point_builds'   => $point_builds,
            'count_builds'   => $count_builds,
            'ranking_builds' => $ranking_builds,
            'total_points'   => $total_points,
        ];
    }

    /**
     * Alliance block (data with owner plus the alliance's STATPOINTS type 2 row); empty arrays without an alliance.
     *
     * @param array<mixed> $UserQuery
     * @return array<mixed>
     */
    private function allianceVars(array $UserQuery): array
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        // Alliance data
        $allianceData = [];
        $allianceStats = [];

        $allyID = $UserQuery['ally_id'];

        if ($allyID != 0) {
            $sql = "SELECT a.`id`, a.`ally_owner`, u.`username`, a.`ally_tag`, a.`ally_name`, a.`ally_web`, a.`ally_description`, a.`ally_text`,
            a.`ally_request`, a.`ally_image`, a.`ally_members`, a.`ally_register_time`
            FROM %%ALLIANCE%% a
            LEFT JOIN %%USERS%% u ON u.`id` = a.`ally_owner`
            WHERE a.`id` = :allyID;";
            $AllianceQuery = $db->selectSingle($sql, [
                ':allyID' => $allyID,
            ]);

            $allianceData['id'] = $UserQuery['ally_id'];
            $allianceData['leader'] = $AllianceQuery['username'] . ' (' . $AllianceQuery['ally_owner'] . ')';
            $allianceData['tag'] = $AllianceQuery['ally_tag'];
            $allianceData['name'] = $AllianceQuery['ally_name'];
            $allianceData['externalText'] = empty($AllianceQuery['ally_description']) ? false : nl2br($AllianceQuery['ally_description']);
            $allianceData['internalText'] = empty($AllianceQuery['ally_text']) ? false : nl2br($AllianceQuery['ally_text']);
            $allianceData['applyText'] = empty($AllianceQuery['ally_request']) ? false : nl2br($AllianceQuery['ally_request']);
            $allianceData['logo'] = empty($AllianceQuery['ally_image']) ? false : $AllianceQuery['ally_image'];
            $allianceData['website'] = empty($AllianceQuery['ally_web']) ? false : $AllianceQuery['ally_web'];
            $allianceData['foundationDate'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $AllianceQuery['ally_register_time'], $this->state->USER['timezone']);
            $allianceData['memberAmount'] = $AllianceQuery['ally_members'];

            $sql = "SELECT `tech_count`, `defs_count`, `fleet_count`, `build_count`, `build_points`, `tech_points`,
            `defs_points`, `fleet_points`, `tech_rank`, `build_rank`, `defs_rank`, `fleet_rank`, `total_points`,
            `stat_type`
            FROM %%STATPOINTS%% WHERE `id_owner` = :allyID AND `stat_type` = 2";
            $allianceStats = $db->selectSingle($sql, [
                ':allyID' => $allyID,
            ]);
        }
        return [
            'allianceData'  => $allianceData,
            'allianceStats' => $allianceStats,
        ];
    }

    /**
     * Planet block: per planet/moon the overview, resource and destroyed rows plus the building, fleet and defense
     * matrices over all living planets (columns: `planetCols`).
     *
     * @return array<mixed>
     */
    private function planetVars(mixed $id_u): array
    {
        $e = $this->elements();
        $db = $this->db;
        $planetCols = [];

        // Planet data
        $planetColumns = [
            '`planet_type`', '`id`', '`name`', '`galaxy`', '`system`', '`planet`', '`destruyed`', '`diameter`',
            '`field_current`', '`field_max`', '`temp_min`', '`temp_max`', '`metal`', '`crystal`', '`deuterium`',
            '`energy`', '`energy_used`',
        ];
        foreach (array_merge($e->ids('fleet'), $e->ids('build'), $e->ids('defense')) as $ID) {
            $planetColumns[] = '`' . $e->column($ID) . '`';
        }
        $PlanetsQuery = $db->fetchAll(
            $db->qb()
                ->select(...$planetColumns)
                ->from($db->table('PLANETS'))
                ->where('`id_owner` = :userID')
                ->setParameter('userID', $id_u),
        );

        $planetRows = [];
        $resourceRows = [];
        $destroyedRows = [];
        $hasDestroyedPlanets = false;
        $listedPlanets = [];
        foreach ($PlanetsQuery as $planetData) {
            $planetLabel = [
                'id'     => $planetData['id'],
                'name'   => $planetData['name'],
                'isMoon' => $planetData['planet_type'] == PlanetType::Moon->value,
                'coords' => sprintf('[%d:%d:%d]', $planetData['galaxy'], $planetData['system'], $planetData['planet']),
            ];

            if ($planetData["destruyed"] == 0) {
                $listedPlanets[] = $planetData;
                $planetCols[] = $planetLabel;
                $planetRows[] = $this->planetOverviewRow($planetLabel, $planetData);
                $resourceRows[] = $this->planetResourceRow($planetLabel, $planetData);
            }

            if ($planetData["destruyed"] > 0) {
                $destroyedRows[] = [
                    'name'   => $planetData['name'],
                    'id'     => $planetData['id'],
                    'coords' => $planetLabel['coords'],
                    'time'   => date("d-m-Y   H:i:s", $planetData['destruyed']),
                ];
                $hasDestroyedPlanets = true;
            }
        }
        $buildingRows = $this->elementMatrix($e->ids('build'), $listedPlanets);
        $fleetRows = $this->elementMatrix($e->ids('fleet'), $listedPlanets);
        $defenseRows = $this->elementMatrix($e->ids('defense'), $listedPlanets);

        return [
            'planetRows'          => $planetRows,
            'planetCols'          => $planetCols,
            'resourceRows'        => $resourceRows,
            'buildingRows'        => $buildingRows,
            'fleetRows'           => $fleetRows,
            'defenseRows'         => $defenseRows,
            'hasDestroyedPlanets' => $hasDestroyedPlanets,
            'destroyedRows'       => $destroyedRows,
        ];
    }

    /**
     * Overview row of a living planet: label plus diameter, fields (calculated and stored maximum) and temperatures.
     *
     * @param array<string, mixed> $planetLabel id, name, isMoon, coords
     * @param array<string, mixed> $planetData the PLANETS row
     * @return array<string, mixed>
     */
    private function planetOverviewRow(array $planetLabel, array $planetData): array
    {
        return $planetLabel + [
            'diameter' => $this->viewHelpers()->prettyNumber($planetData['diameter']),
            'fields'   => $this->viewHelpers()->prettyNumber($planetData['field_current']) . ' / '
                . $this->viewHelpers()->prettyNumber($this->planetQueries()->calculateMaxPlanetFields($planetData)),
            'fieldsDb' => $this->viewHelpers()->prettyNumber($planetData['field_current']) . ' / '
                . $this->viewHelpers()->prettyNumber($planetData['field_max']),
            'temp'     => $this->viewHelpers()->prettyNumber($planetData['temp_min']) . ' / '
                . $this->viewHelpers()->prettyNumber($planetData['temp_max']),
        ];
    }

    /**
     * Resource row of a living planet: label plus metal, crystal, deuterium, energy balance and energy as short and full
     * number (`energySum` also carries the sign).
     *
     * @param array<string, mixed> $planetLabel id, name, isMoon, coords
     * @param array<string, mixed> $planetData the PLANETS row
     * @return array<string, mixed>
     */
    private function planetResourceRow(array $planetLabel, array $planetData): array
    {
        $SumOfEnergy = ($planetData['energy'] + $planetData['energy_used']);

        return $planetLabel + [
            'metal'     => ['short' => $this->viewHelpers()->shortlyNumber($planetData['metal']), 'full' => $this->viewHelpers()->prettyNumber($planetData['metal'])],
            'crystal'   => ['short' => $this->viewHelpers()->shortlyNumber($planetData['crystal']), 'full' => $this->viewHelpers()->prettyNumber($planetData['crystal'])],
            'deuterium' => ['short' => $this->viewHelpers()->shortlyNumber($planetData['deuterium']), 'full' => $this->viewHelpers()->prettyNumber($planetData['deuterium'])],
            'energySum' => ['short' => $this->viewHelpers()->shortlyNumber($SumOfEnergy), 'full' => $this->viewHelpers()->prettyNumber($SumOfEnergy), 'sign' => $SumOfEnergy <=> 0],
            'energy'    => ['short' => $this->viewHelpers()->shortlyNumber($planetData['energy']), 'full' => $this->viewHelpers()->prettyNumber($planetData['energy'])],
        ];
    }

    /**
     * Matrix of element levels over the living planets: one row per element (translated name) with a short/full number
     * cell per planet.
     *
     * @param int[] $ids element ids
     * @param array<int, array<string, mixed>> $listedPlanets the living planets (columns)
     * @return list<array{name: string, cells: list<array{short: string, full: string}>}>
     */
    private function elementMatrix(array $ids, array $listedPlanets): array
    {
        $e = $this->elements();
        $LNG = $this->state->LNG;

        $rows = [];
        foreach ($ids as $ID) {
            $cells = [];
            foreach ($listedPlanets as $planetData) {
                $cells[] = [
                    'short' => $this->viewHelpers()->shortlyNumber($planetData[$e->column($ID)]),
                    'full'  => $this->viewHelpers()->prettyNumber($planetData[$e->column($ID)]),
                ];
            }
            $rows[] = ['name' => $LNG['tech'][$ID], 'cells' => $cells];
        }
        return $rows;
    }

    /**
     * Intro page: pick a player from the users the admin may see (authlevel up to the admin's, emulated universe).
     */
    private function showUserPicker(): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $sql = "SELECT `id`, `username`, `authlevel` FROM %%USERS%%
        WHERE `authlevel` <= :userLevel AND `universe` = :universe
        ORDER BY `username` ASC;";
        $UserQuerry = $db->select($sql, [
            ':userLevel' => $this->state->USER['authlevel'],
            ':universe'  => $this->universe()->getEmulated(),
        ]);

        $Userlist = "";
        foreach ($UserQuerry as $User) {
            $Userlist .= sprintf(
                "<option value=\"%d\">%s&nbsp;&nbsp;(%s)</option>",
                $User['id'],
                GameEscaping::escape((string) $User['username'], 'UTF-8'),
                $LNG['se_rank'][$User['authlevel']],
            );
        }

        $this->assign([
            'Userlist'         => new Markup($Userlist, 'UTF-8'),
            'ac_enter_user_id' => $LNG['ac_enter_user_id'],
            'bo_select_title'  => $LNG['bo_select_title'],
            'button_filter'    => $LNG['button_filter'],
            'button_deselect'  => $LNG['button_deselect'],
            'ac_select_id_num' => $LNG['ac_select_id_num'],
            'button_submit'    => $LNG['button_submit'],
        ]);
        return $this->render('admin/AccountDataPageIntro.html.twig');
    }
}
