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

use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;
use App\Twig\GameEscaping;
use Twig\Markup;

/** Read-only overview of one player: account, planets, resources, buildings, fleet and defense. */
#[\App\Routing\GamePage('admin.php', 'accountData')]
#[\App\Routing\GamePage('admin.php', 'accountData', uniPrefix: true)]
class AccountDataController extends AbstractAdminController
{
    // The former ShowAccountDataPage did not extend AbstractAdminPage: only the admin entry
    // checks applied, no per-page permission check.
    protected function construct(): void {}

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $id_u = $this->http()->_GP('id_u', 0);
        if (!empty($id_u)) {
            $sql = "SELECT `id`, `authlevel` FROM %%USERS%% WHERE `id` = :userID;";
            $OnlyQueryLogin = $db->selectSingle($sql, [
                ':userID' => $id_u,
            ]);
            if (!isset($OnlyQueryLogin)) {
                $this->message($LNG['ac_username_doesnt'], '?page=accoutdata');
            } else {
                // User data
                $sql = "SELECT ac.`id` as account_id, ac.`accountname`, u.`id`, u.`username`, u.`authlevel`, u.`id_planet`, u.`galaxy`,
                u.`system`, u.`planet`, u.`user_lastip`, u.`ip_at_reg`, u.`register_time`, u.`onlinetime`,
                u.`urlaubs_modus`, u.`urlaubs_until`, u.`ally_id`, u.`ally_register_time`,
                u.`ally_rank_id`, u.`bana`, u.`banaday`, ";
                foreach ($reslist['tech'] as $ID) {
                    $sql .= " u.`" . $resource[$ID] . "`, ";
                }
                $sql = rtrim($sql, ', ') . " FROM %%USERS%% as u
                JOIN %%ACCOUNTS_TO_USERS%% au ON au.`user_id` = u.`id`
                JOIN %%ACCOUNTS%% ac ON ac.`id` = au.`account_id`
                WHERE u.`id` = :userID;";
                $UserQuery = $db->selectSingle($sql, [
                    ':userID' => $id_u,
                ]);

                if (!isset($UserQuery['user_ua'])) {
                    $UserQuery['user_ua'] = '';
                }

                $reg_time = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $UserQuery['register_time'], $USER['timezone']);
                $onlinetime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $UserQuery['onlinetime'], $USER['timezone']);
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
                foreach ($reslist['tech'] as $ID) {
                    $technologyRows[] = [
                        'name'  => $LNG['tech'][$ID],
                        'level' => $UserQuery[$resource[$ID]],
                    ];
                }

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

                    $sus_longer = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BannedQuery['longer'], $USER['timezone']);
                    $sus_time = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $BannedQuery['time'], $USER['timezone']);
                    $sus_reason = $BannedQuery['theme'];
                    $sus_author = $BannedQuery['author'];
                }

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
                    $allianceData['foundationDate'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $AllianceQuery['ally_register_time'], $USER['timezone']);
                    $allianceData['memberAmount'] = $AllianceQuery['ally_members'];

                    $sql = "SELECT `tech_count`, `defs_count`, `fleet_count`, `build_count`, `build_points`, `tech_points`,
                    `defs_points`, `fleet_points`, `tech_rank`, `build_rank`, `defs_rank`, `fleet_rank`, `total_points`,
                    `stat_type`
                    FROM %%STATPOINTS%% WHERE `id_owner` = :allyID AND `stat_type` = 2";
                    $allianceStats = $db->selectSingle($sql, [
                        ':allyID' => $allyID,
                    ]);
                }
                $planetCols = [];

                // Planet data
                $sql = "SELECT `planet_type`, `id`, `name`, `galaxy`, `system`, `planet`, `destruyed`, `diameter`, `field_current`, `field_max`,
                `temp_min`, `temp_max`, `metal`, `crystal`, `deuterium`, `energy`, `energy_used`, ";
                foreach (array_merge($reslist['fleet'], $reslist['build'], $reslist['defense']) as $ID) {
                    $sql .= "`" . $resource[$ID] . "`, ";
                }
                $sql = rtrim($sql, ', ') . "FROM %%PLANETS%% WHERE `id_owner` = :userID;";
                $PlanetsQuery = $db->select($sql, [
                    ':userID' => $id_u,
                ]);

                $planetRows = [];
                $resourceRows = [];
                $destroyedRows = [];
                $hasDestroyedPlanets = false;
                $canEditPlanets = $this->viewHelpers()->allowedTo('ShowQuickEditorPage');
                $listedPlanets = [];
                foreach ($PlanetsQuery as $planetData) {
                    $planetLabel = [
                        'id'     => $planetData['id'],
                        'name'   => $planetData['name'],
                        'isMoon' => $planetData['planet_type'] == 3,
                        'coords' => sprintf('[%d:%d:%d]', $planetData['galaxy'], $planetData['system'], $planetData['planet']),
                    ];

                    if ($planetData["destruyed"] == 0) {
                        $listedPlanets[] = $planetData;
                        $planetCols[] = $planetLabel;
                        $planetRows[] = $planetLabel + [
                            'diameter' => $this->viewHelpers()->prettyNumber($planetData['diameter']),
                            'fields'   => $this->viewHelpers()->prettyNumber($planetData['field_current']) . ' / '
                                . $this->viewHelpers()->prettyNumber($this->playerUtil()->calculateMaxPlanetFields($planetData)),
                            'fieldsDb' => $this->viewHelpers()->prettyNumber($planetData['field_current']) . ' / '
                                . $this->viewHelpers()->prettyNumber($planetData['field_max']),
                            'temp'     => $this->viewHelpers()->prettyNumber($planetData['temp_min']) . ' / '
                                . $this->viewHelpers()->prettyNumber($planetData['temp_max']),
                        ];

                        $SumOfEnergy = ($planetData['energy'] + $planetData['energy_used']);

                        $resourceRows[] = $planetLabel + [
                            'metal'     => ['short' => $this->viewHelpers()->shortlyNumber($planetData['metal']), 'full' => $this->viewHelpers()->prettyNumber($planetData['metal'])],
                            'crystal'   => ['short' => $this->viewHelpers()->shortlyNumber($planetData['crystal']), 'full' => $this->viewHelpers()->prettyNumber($planetData['crystal'])],
                            'deuterium' => ['short' => $this->viewHelpers()->shortlyNumber($planetData['deuterium']), 'full' => $this->viewHelpers()->prettyNumber($planetData['deuterium'])],
                            'energySum' => ['short' => $this->viewHelpers()->shortlyNumber($SumOfEnergy), 'full' => $this->viewHelpers()->prettyNumber($SumOfEnergy), 'sign' => $SumOfEnergy <=> 0],
                            'energy'    => ['short' => $this->viewHelpers()->shortlyNumber($planetData['energy']), 'full' => $this->viewHelpers()->prettyNumber($planetData['energy'])],
                        ];
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
                $elementMatrix = function (array $ids) use ($listedPlanets, $resource, $LNG) {
                    $rows = [];
                    foreach ($ids as $ID) {
                        $cells = [];
                        foreach ($listedPlanets as $planetData) {
                            $cells[] = [
                                'short' => $this->viewHelpers()->shortlyNumber($planetData[$resource[$ID]]),
                                'full'  => $this->viewHelpers()->prettyNumber($planetData[$resource[$ID]]),
                            ];
                        }
                        $rows[] = ['name' => $LNG['tech'][$ID], 'cells' => $cells];
                    }
                    return $rows;
                };
                $buildingRows = $elementMatrix($reslist['build']);
                $fleetRows = $elementMatrix($reslist['fleet']);
                $defenseRows = $elementMatrix($reslist['defense']);

                $this->assign([
                    'planetRows'                => $planetRows,
                    'planetCols'                => $planetCols,
                    'resourceRows'              => $resourceRows,
                    'buildingRows'              => $buildingRows,
                    'fleetRows'                 => $fleetRows,
                    'defenseRows'               => $defenseRows,
                    'technologyRows'            => $technologyRows,
                    'hasDestroyedPlanets'       => $hasDestroyedPlanets,
                    'destroyedRows'             => $destroyedRows,
                    'allianceData'              => $allianceData,
                    'allianceStats'             => $allianceStats,

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
                    'account_id'     => $account_id,
                    'accountname'    => $accountname,
                    'id'             => $id,
                    'nombre'         => $nombre,
                    'nivel'          => $nivel,
                    'vacas'          => $vacas,
                    'suspen'         => $suspen,
                    'mas'            => $mas,
                    'ip'             => $ip,
                    'ip2'            => $ip2,
                    'ipcheck'        => $LNG['one_is_yes_1'],
                    'reg_time'       => $reg_time,
                    'onlinetime'     => $onlinetime,
                    'id_p'           => $id_p,
                    'g'              => $g,
                    's'              => $s,
                    'p'              => $p,
                    'info'           => $info,
                    'sus_time'       => $sus_time,
                    'sus_longer'     => $sus_longer,
                    'sus_reason'     => $sus_reason,
                    'sus_author'     => $sus_author,
                    'canedit'        => $canEditPlanets,
                    'Metal'          => $LNG['tech'][901],
                    'Crystal'        => $LNG['tech'][902],
                    'Deuterium'      => $LNG['tech'][903],
                    'Energy'         => $LNG['tech'][911],
                ]);
                return $this->render('admin/AccountDataPageDetail.html.twig');
            }
            throw new \App\Game\Core\EndOfRequest();
        }
        $sql = "SELECT `id`, `username`, `authlevel` FROM %%USERS%%
        WHERE `authlevel` <= :userLevel AND `universe` = :universe
        ORDER BY `username` ASC;";
        $UserQuerry = $db->select($sql, [
            ':userLevel' => $USER['authlevel'],
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
