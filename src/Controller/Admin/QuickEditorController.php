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

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\AccountUtil;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Log;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Quick editor for single planets and players (resources, buildings, fleets, authlevel, ...).
 */
#[\App\Routing\GamePage('admin.php', 'quickEditor')]
#[\App\Routing\GamePage('admin.php', 'quickEditor', uniPrefix: true)]
class QuickEditorController extends AbstractAdminController
{
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $action = $this->http()->_GP('action', '');
        $edit = $this->http()->_GP('edit', '');
        $id = $this->http()->_GP('id', 0);

        $db = $this->db;

        switch ($edit) {
            case 'planet':
                $DataIDs = array_merge($reslist['fleet'], $reslist['build'], $reslist['defense']);
                $SpecifyItemsPQ = "";

                foreach ($DataIDs as $ID) {
                    $SpecifyItemsPQ .= "`" . $resource[$ID] . "`,";
                }
                $sql = "SELECT " . $SpecifyItemsPQ . " `name`, `id_owner`, `planet_type`, `galaxy`, `system`, `planet`, `destruyed`,
                `diameter`, `field_current`, `field_max`, `temp_min`, `temp_max`, `metal`, `crystal`, `deuterium`
                FROM %%PLANETS%% WHERE `id` = :planetID;";
                $PlanetData = $db->selectSingle($sql, [
                    ':planetID' => $id,
                ]);

                if ($action == 'send') {
                    $sql = "UPDATE %%PLANETS%% SET ";
                    $Fields = $PlanetData['field_current'];
                    foreach ($DataIDs as $ID) {
                        $level = min(
                            max(0, round($this->http()->_GP($resource[$ID], 0.0))),
                            (in_array($ID, $reslist['build']) ? 255 : 18446744073709551615),
                        );

                        if (in_array($ID, $reslist['allow'][$PlanetData['planet_type']])) {
                            $Fields += $level - $PlanetData[$resource[$ID]];
                        }

                        $sql .= "`" . $resource[$ID] . "` = " . $level . ", ";
                    }

                    $sql .= "`metal` = :metal, ";
                    $sql .= "`crystal` = :crystal, ";
                    $sql .= "`deuterium` = :deuterium, ";
                    $sql .= "`field_current` = :fields, ";
                    $sql .= "`field_max` = :field_max, ";
                    $sql .= "`name` = :name, ";
                    $sql .= "`eco_hash` = '' ";
                    $sql .= "WHERE `id` = :planetID AND `universe` = :universe;";

                    $db->update($sql, [
                        ':metal'     => max(0, round($this->http()->_GP('metal', 0.0))),
                        ':crystal'   => max(0, round($this->http()->_GP('crystal', 0.0))),
                        ':deuterium' => max(0, round($this->http()->_GP('deuterium', 0.0))),
                        ':fields'    => $Fields,
                        ':field_max' => $this->http()->_GP('field_max', 0),
                        ':name'      => $this->http()->_GP('name', '', UTF8_SUPPORT),
                        ':planetID'  => $id,
                        ':universe'  => $this->universe()->getEmulated(),
                    ]);

                    $old = [];
                    $new = [];
                    foreach (array_merge($DataIDs, $reslist['resstype'][1]) as $IDs) {
                        $old[$IDs] = $PlanetData[$resource[$IDs]];
                        $new[$IDs] = max(0, round($this->http()->_GP($resource[$IDs], 0.0)));
                    }
                    $old['field_max'] = $PlanetData['field_max'];
                    $new['field_max'] = $this->http()->_GP('field_max', 0);
                    $LOG = new Log($this->db, $this->configs, $this->state, 2);
                    $LOG->target = $id;
                    $LOG->old = $old;
                    $LOG->new = $new;
                    $LOG->save();

                    throw new \App\Game\Core\EndOfRequest($LNG->format('qe_edit_planet_sucess',
                        $PlanetData['name'],
                        $PlanetData['galaxy'],
                        $PlanetData['system'],
                        $PlanetData['planet'],
                    ));
                }

                $sql = "SELECT `username` FROM %%USERS%%
                WHERE `id` = :userID AND universe = :universe;";
                $UserInfo = $db->selectSingle($sql, [
                    ':userID'   => $PlanetData['id_owner'],
                    ':universe' => $this->universe()->getEmulated(),
                ]);

                $build = $defense = $fleet = [];

                foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                    $build[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($PlanetData[$resource[$ID]]),
                        'input' => $PlanetData[$resource[$ID]],
                    ];
                }

                foreach ($reslist['fleet'] as $ID) {
                    $fleet[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($PlanetData[$resource[$ID]]),
                        'input' => $PlanetData[$resource[$ID]],
                    ];
                }

                foreach ($reslist['defense'] as $ID) {
                    $defense[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($PlanetData[$resource[$ID]]),
                        'input' => $PlanetData[$resource[$ID]],
                    ];
                }

                $this->assign([
                    'build'        => $build,
                    'fleet'        => $fleet,
                    'defense'      => $defense,
                    'id'           => $id,
                    'ownerid'      => $PlanetData['id_owner'],
                    'ownername'    => $UserInfo['username'],
                    'name'         => $PlanetData['name'],
                    'galaxy'       => $PlanetData['galaxy'],
                    'system'       => $PlanetData['system'],
                    'planet'       => $PlanetData['planet'],
                    'field_min'    => $PlanetData['field_current'],
                    'field_max'    => $PlanetData['field_max'],
                    'temp_min'     => $PlanetData['temp_min'],
                    'temp_max'     => $PlanetData['temp_max'],
                    'metal'        => floatToString($PlanetData['metal']),
                    'crystal'      => floatToString($PlanetData['crystal']),
                    'deuterium'    => floatToString($PlanetData['deuterium']),
                    'metal_c'      => $this->viewHelpers()->prettyNumber($PlanetData['metal']),
                    'crystal_c'    => $this->viewHelpers()->prettyNumber($PlanetData['crystal']),
                    'deuterium_c'  => $this->viewHelpers()->prettyNumber($PlanetData['deuterium']),
                ]);
                return $this->render('admin/QuickEditorPlanet.html.twig');
            case 'player':
                $DataIDs = $reslist['tech'];
                $SpecifyItemsPQ = "";

                foreach ($DataIDs as $ID) {
                    $SpecifyItemsPQ .= "`" . $resource[$ID] . "`,";
                }
                $sql = "SELECT " . $SpecifyItemsPQ . " `username`, `authlevel`, `galaxy`, `system`, `planet`, `id_planet`, `authattack`, `authlevel`
                FROM %%USERS%%
                WHERE `id` = :userID;";
                $UserData = $db->selectSingle($sql, [':userID' => $id]);
                $ChangePW = $USER['id'] == ROOT_USER || ($id != ROOT_USER && $USER['authlevel'] > $UserData['authlevel']);

                if ($action == 'send') {
                    $sql = "UPDATE %%USERS%% SET ";
                    foreach ($DataIDs as $ID) {
                        $sql .= "`" . $resource[$ID] . "` = " . min(abs($this->http()->_GP($resource[$ID], 0)), 255) . ", ";
                    }
                    if (!empty($this->state->requestPost['password']) && $ChangePW) {
                        $sql .= "`password` = '" . $this->accountUtil()->cryptPassword($this->http()->_GP('password', '', true)) . "', ";
                    }

                    $sql .= "`username` = :username, ";
                    $sql .= "`authattack` = :authattack ";
                    $sql .= "WHERE `id` = :userID AND `universe` = :universe;";
                    $db->update($sql, [
                        ':username'   => $this->http()->_GP('name', '', UTF8_SUPPORT),
                        ':authattack' => ($UserData['authlevel'] != AUTH_USR && $this->http()->_GP('authattack', '') == 'on' ? $UserData['authlevel'] : 0),
                        ':userID'     => $id,
                        ':universe'   => $this->universe()->getEmulated(),
                    ]);

                    $old = [];
                    $new = [];
                    foreach ($DataIDs as $IDs) {
                        $old[$IDs] = $UserData[$resource[$IDs]];
                        $new[$IDs] = abs($this->http()->_GP($resource[$IDs], 0));
                    }
                    $old['username'] = $UserData['username'];
                    $new['username'] = $this->http()->_GP('name', '', UTF8_SUPPORT);
                    $old['authattack'] = $UserData['authattack'];
                    $new['authattack'] = ($UserData['authlevel'] != AUTH_USR
                    && $this->http()->_GP('authattack', '') == 'on' ? $UserData['authlevel'] : 0);

                    $LOG = new Log($this->db, $this->configs, $this->state, 1);
                    $LOG->target = $id;
                    $LOG->old = $old;
                    $LOG->new = $new;
                    $LOG->save();

                    throw new \App\Game\Core\EndOfRequest($LNG->format('qe_edit_player_sucess', $UserData['username'], $id));
                }
                $sql = "SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetID AND `universe` = :universe;";
                $PlanetInfo = $db->selectSingle($sql, [
                    ':planetID' => $UserData['id_planet'],
                    ':universe' => $this->universe()->getEmulated(),
                ]);

                $tech = [];

                foreach ($reslist['tech'] as $ID) {
                    $tech[] = [
                        'type'  => $resource[$ID],
                        'name'  => $LNG['tech'][$ID],
                        'count' => $this->viewHelpers()->prettyNumber($UserData[$resource[$ID]]),
                        'input' => $UserData[$resource[$ID]],
                    ];
                }

                $this->assign([
                    'tech'         => $tech,
                    'id'           => $id,
                    'planetid'     => $UserData['id_planet'],
                    'planetname'   => $PlanetInfo['name'],
                    'name'         => $UserData['username'],
                    'galaxy'       => $UserData['galaxy'],
                    'system'       => $UserData['system'],
                    'planet'       => $UserData['planet'],
                    'authlevel'    => $UserData['authlevel'],
                    'qe_change'    => '',
                    'authattack'   => $UserData['authattack'],
                    'ChangePW'     => $ChangePW,
                    'yesorno'      => [1 => $LNG['one_is_yes_1'], 0 => $LNG['one_is_yes_0']],
                ]);
                return $this->render('admin/QuickEditorUser.html.twig');
        }

        // nothing selected to edit: empty page, as before
        return new Response();
    }
}
