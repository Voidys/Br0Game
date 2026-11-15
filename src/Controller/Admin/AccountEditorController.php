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

# Actions not logged: Planet-Edit, Alliance-Edit

namespace App\Controller\Admin;

use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Log;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;

/** Edits a player: resources, ships, defenses, buildings, research, personal data, planets and alliance. */
#[\App\Routing\GamePage('admin.php', 'accountEditor')]
#[\App\Routing\GamePage('admin.php', 'accountEditor', uniPrefix: true)]
class AccountEditorController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $db = $this->db;
        if (!isset($this->state->requestQuery['edit'])) {
            $this->state->requestQuery['edit'] = '';
        }
        switch ($this->state->requestQuery['edit']) {
            case 'resources':
                $id = $this->http()->_GP('id', 0);
                $metal = max(0, round($this->http()->_GP('metal', 0.0)));
                $cristal = max(0, round($this->http()->_GP('cristal', 0.0)));
                $deut = max(0, round($this->http()->_GP('deut', 0.0)));

                if ($this->state->requestPost) {
                    if (!empty($id)) {
                        $sql = "SELECT `metal`, `crystal`, `deuterium` FROM %%PLANETS%%
                        WHERE `id` = :planetID;";
                        $beforeRess = $db->selectSingle($sql, [
                            ':planetID' => $id,
                        ]);
                    }
                    if (isset($this->state->requestPost['add'])) {
                        if (!empty($id)) {
                            $sql = "UPDATE %%PLANETS%% SET `metal` = `metal` + :metal, `crystal` = `crystal` + :cristal,
                            `deuterium` = `deuterium` + :deut
                            WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, [
                                ':metal'    => $metal,
                                ':cristal'  => $cristal,
                                ':deut'     => $deut,
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]);
                            $afterRess = [
                                'metal'     => ($beforeRess['metal'] + $metal),
                                'crystal'   => ($beforeRess['crystal'] + $cristal),
                                'deuterium' => ($beforeRess['deuterium'] + $deut),
                            ];
                        }
                    } elseif (isset($this->state->requestPost['delete'])) {
                        if (!empty($id)) {
                            $sql = "UPDATE %%PLANETS%% SET `metal` = `metal` - :metal, `crystal` = `crystal` - :cristal,
                            `deuterium` = `deuterium` - :deut
                            WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, [
                                ':metal'    => $metal,
                                ':cristal'  => $cristal,
                                ':deut'     => $deut,
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]);
                            $afterRess = [
                                'metal'     => ($beforeRess['metal'] - $metal),
                                'crystal'   => ($beforeRess['crystal'] - $cristal),
                                'deuterium' => ($beforeRess['deuterium'] - $deut),
                            ];
                        }
                    }

                    if (!empty($id)) {
                        $LOG = new Log($this->db, $this->configs, $this->state, 2);
                        $LOG->target = $id;
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforeRess;
                        $LOG->new = $afterRess;
                        $LOG->save();
                    }
                    if (isset($this->state->requestPost['add'])) {
                        $this->message($LNG['ad_add_res_sucess'], '?page=accountEditor&edit=resources');
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $this->message($LNG['ad_delete_res_sucess'], '?page=accountEditor&edit=resources');
                    }
                    throw new \App\Game\Core\EndOfRequest();
                }

                return $this->render('admin/AccountEditorPageResources.html.twig');
            case 'ships':
                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id', 0);
                    foreach ($reslist['fleet'] as $ID) {
                        $fields[] = "`" . $resource[$ID] . "`";
                    }
                    $sql = "SELECT " . implode(", ", $fields) . " FROM %%PLANETS%% WHERE `id` = :planetID;";
                    $beforeShips = $db->selectSingle($sql, [
                        ':planetID' => $id,
                    ]);
                    $afterShips = [];
                    if (isset($this->state->requestPost['add'])) {
                        $QryUpdate = [];
                        $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                        foreach ($reslist['fleet'] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterShips[$ID] = ($beforeShips[$resource[$ID]] ?? 0) + $count;
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $QryUpdate = [];
                        $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                        foreach ($reslist['fleet'] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterShips[$ID] = max(0, $beforeShips[$ID] - $count);
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    }

                    if (!empty($QryUpdate)) {
                        $LOG = new Log($this->db, $this->configs, $this->state, 2);
                        $LOG->target = $id;
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforeShips;
                        $LOG->new = $afterShips;
                        $LOG->save();
                    }
                    if (isset($this->state->requestPost['add'])) {
                        $this->message($LNG['ad_add_ships_sucess'], '?page=accountEditor&edit=ships');
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $this->message($LNG['ad_delete_ships_sucess'], '?page=accountEditor&edit=ships');
                    }
                    throw new \App\Game\Core\EndOfRequest();
                }

                $parse['ships'] = "";
                foreach ($reslist['fleet'] as $ID) {
                    $INPUT[$ID] = [
                        'type' => $resource[$ID],
                    ];
                }

                $this->assign([
                    'inputlist' => $INPUT,
                ]);

                return $this->render('admin/AccountEditorPageShips.html.twig');

            case 'defenses':
                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id', 0);
                    foreach ($reslist['defense'] as $ID) {
                        $fields[] = "`" . $resource[$ID] . "`";
                    }
                    $sql = "SELECT " . implode(", ", $fields) . " FROM %%PLANETS%% WHERE `id` = :planetID;";
                    $beforeDef = $db->selectSingle($sql, [
                        ':planetID' => $id,
                    ]);
                    $afterDef = [];
                    if (isset($this->state->requestPost['add'])) {
                        $QryUpdate = [];
                        $sql = "UPDATE %%PLANETS%% SET ";
                        foreach ($reslist['defense'] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterDef[$ID] = $beforeDef[$resource[$ID]] + $count;
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $QryUpdate = [];
                        $sql = "UPDATE %%PLANETS%% SET ";
                        foreach ($reslist['defense'] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterDef[$ID] = max(0, $beforeDef[$resource[$ID]] - $count);
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    }

                    if (!empty($QryUpdate)) {
                        $LOG = new Log($this->db, $this->configs, $this->state, 2);
                        $LOG->target = $id;
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforeDef;
                        $LOG->new = $afterDef;
                        $LOG->save();
                    }
                    if (isset($this->state->requestPost['add'])) {
                        $this->message($LNG['ad_add_defenses_success'], '?page=accountEditor&edit=defenses');
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $this->message($LNG['ad_delete_defenses_success'], '?page=accountEditor&edit=defenses');
                    }
                    throw new \App\Game\Core\EndOfRequest();
                }

                foreach ($reslist['defense'] as $ID) {
                    $INPUT[$ID] = ['type' => $resource[$ID]];
                }

                $this->assign([
                    'inputlist' => $INPUT,
                ]);

                return $this->render('admin/AccountEditorPageDefenses.html.twig');
                break;

            case 'buildings':
                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id', 0);
                    $sql = "SELECT * FROM %%PLANETS%% WHERE `id` = :planetID;";
                    $PlanetData = $db->selectSingle($sql, [
                        ':planetID' => $id,
                    ]);
                    if (!isset($PlanetData)) {
                        $this->message($LNG['ad_add_not_exist'], '?page=accountEditor&edit=buildings');
                    }
                    $beforeBuildings = [];
                    $afterBuildings = [];
                    foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                        $beforeBuildings[$ID] = $PlanetData[$resource[$ID]];
                    }
                    if (isset($this->state->requestPost['add'])) {
                        $QryUpdate = [];
                        $fields = 0;
                        $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                        foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterBuildings[$ID] = $beforeBuildings[$ID] + $count;
                                $fields += $count;
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= ", `field_current` = `field_current` + :fields WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':fields'   => $fields,
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $QryUpdate = [];
                        $fields = 0;
                        $sql = "UPDATE %%PLANETS%% SET `eco_hash` = '', ";
                        foreach ($reslist['allow'][$PlanetData['planet_type']] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterBuildings[$ID] = max($beforeBuildings[$ID] - $count, 0);
                                $fields += $count;
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= ", `field_current` = `field_current` - :fields WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':fields'   => $fields,
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    }

                    if (!empty($QryUpdate)) {
                        $LOG = new Log($this->db, $this->configs, $this->state, 2);
                        $LOG->target = $id;
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforeBuildings;
                        $LOG->new = $afterBuildings;
                        $LOG->save();
                    }

                    if (isset($this->state->requestPost['add'])) {
                        $this->message($LNG['ad_add_build_success'], '?page=accountEditor&edit=buildings');
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $this->message($LNG['ad_delete_build_success'], '?page=accountEditor&edit=buildings');
                    }
                    throw new \App\Game\Core\EndOfRequest();
                }

                foreach ($reslist['build'] as $ID) {
                    $INPUT[$ID] = ['type' => $resource[$ID]];
                }

                $this->assign([
                    'inputlist' => $INPUT,
                ]);

                return $this->render('admin/AccountEditorPageBuilds.html.twig');

            case 'researchs':
                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id', 0);
                    foreach ($reslist['tech'] as $ID) {
                        $fields[] = "`" . $resource[$ID] . "`";
                    }
                    $sql = "SELECT " . implode(", ", $fields) . " FROM %%USERS%% WHERE `id` = :userID;";
                    $beforeResearch = $db->selectSingle($sql, [
                        ':userID' => $id,
                    ]);
                    $afterResearch = [];
                    if (isset($this->state->requestPost['add'])) {
                        $QryUpdate = [];
                        $sql = "UPDATE %%USERS%% SET ";
                        foreach ($reslist['tech'] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` + :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterResearch[$ID] = $beforeResearch[$resource[$ID]] + $count;
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $QryUpdate = [];
                        $sql = "UPDATE %%USERS%% SET ";
                        foreach ($reslist['tech'] as $ID) {
                            $count = max(0, round($this->http()->_GP($resource[$ID], 0.0)));
                            if ($count > 0) {
                                $QryUpdate[] = "`" . $resource[$ID] . "` = `" . $resource[$ID] . "` - :" . $resource[$ID];
                                $params[':' . $resource[$ID]] = $count;
                                $afterResearch[$ID] = max(0, $beforeResearch[$resource[$ID]] - $count);
                            }
                        }
                        if (!empty($QryUpdate)) {
                            $sql .= implode(", ", $QryUpdate);
                            $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                            $db->update($sql, array_merge($params, [
                                ':id'       => $id,
                                ':universe' => $this->universe()->getEmulated(),
                            ]));
                        }
                    }

                    if (!empty($QryUpdate)) {
                        $LOG = new Log($this->db, $this->configs, $this->state, 1);
                        $LOG->target = $id;
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforeResearch;
                        $LOG->new = $afterResearch;
                        $LOG->save();
                    }
                    if (isset($this->state->requestPost['add'])) {
                        $this->message($LNG['ad_add_tech_success'], '?page=accountEditor&edit=researchs');
                    } elseif (isset($this->state->requestPost['delete'])) {
                        $this->message($LNG['ad_delete_tech_success'], '?page=accountEditor&edit=researchs');
                    }
                    throw new \App\Game\Core\EndOfRequest();
                }

                foreach ($reslist['tech'] as $ID) {
                    $INPUT[$ID] = ['type' => $resource[$ID]];
                }

                $this->assign([
                    'inputlist' => $INPUT,
                ]);

                return $this->render('admin/AccountEditorPageResearch.html.twig');
            case 'personal':
                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id', 0);
                    $username = $this->http()->_GP('username', '', UTF8_SUPPORT);
                    $vacation = $this->http()->_GP('vacation', '');

                    $sql = "SELECT `username`,`urlaubs_modus`,`urlaubs_until`,`universe`
                    FROM %%USERS%% WHERE `id` = :userID;";
                    $beforePersonal = $db->selectSingle($sql, [
                        ':userID' => $id,
                    ]);
                    $afterPersonal = $beforePersonal;
                    $QryUpdate = [];
                    $params = [];

                    if (!empty($username) && $id != ROOT_USER) {
                        $QryUpdate[] = "`username` = :username";
                        $params[':username'] = $username;
                        $afterPersonal['username'] = $username;
                    }

                    if ($vacation == 'yes') {
                        $QryUpdate[] = "`urlaubs_modus` = :urlaubs_modus";
                        $params[':urlaubs_modus'] = 1;
                        $afterPersonal['urlaubs_modus'] = 1;
                        $d = $this->http()->_GP('d', 0);
                        $h = $this->http()->_GP('h', 0);
                        $m = $this->http()->_GP('m', 0);
                        $s = $this->http()->_GP('s', 0);
                        $TimeAns = $this->state->runtime->timestamp + $d * 86400 + $h * 3600 + $m * 60 + $s;
                        $QryUpdate[] = "`urlaubs_until` = :urlaubs_until";
                        $params[':urlaubs_until'] = $TimeAns;
                        $afterPersonal['urlaubs_until'] = $TimeAns;
                    }

                    $sql = "UPDATE %%USERS%% SET ";
                    $sql .= implode(", ", $QryUpdate);
                    $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                    $db->update($sql, array_merge($params, [
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]));

                    $LOG = new Log($this->db, $this->configs, $this->state, 1);
                    $LOG->target = $id;
                    $LOG->universe = $this->universe()->getEmulated();
                    $LOG->old = $beforePersonal;
                    $LOG->new = $afterPersonal;
                    $LOG->save();
                    $this->message($LNG['ad_personal_succes'], '?page=accountEditor&edit=personal');
                    throw new \App\Game\Core\EndOfRequest();
                }

                $this->assign([
                    'Selector' => ['' => $LNG['select_option'], 'yes' => $LNG['one_is_yes_1'], 'no' => $LNG['one_is_yes_0']],
                ]);

                return $this->render('admin/AccountEditorPagePersonal.html.twig');

            case 'planets':
                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id', 0);
                    $name = $this->http()->_GP('name', '', UTF8_SUPPORT);
                    $diameter = $this->http()->_GP('diameter', 0);
                    $fields = $this->http()->_GP('fields', 0);
                    $buildings = $this->http()->_GP('0_buildings', '');
                    $ships = $this->http()->_GP('0_ships', '');
                    $defenses = $this->http()->_GP('0_defenses', '');
                    $c_hangar = $this->http()->_GP('0_c_hangar', '');
                    $c_buildings = $this->http()->_GP('0_c_buildings', '');
                    $change_pos = $this->http()->_GP('change_position', '');
                    $galaxy = $this->http()->_GP('galaxy', 0);
                    $system = $this->http()->_GP('system', 0);
                    $planet = $this->http()->_GP('planet', 0);

                    $sql = "SELECT p.*, u.`authlevel` FROM %%PLANETS%% p
                        JOIN %%USERS%% u ON u.`id` = p.`id_owner`
                        WHERE p.`id` = :planetID AND p.`universe` = :universe;";
                    $beforePlanet = $db->selectSingle($sql, [
                        ':planetID' => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]);

                    if (empty($beforePlanet)) {
                        $this->message($LNG['ad_pla_error_planets2'], '?page=accountEditor&edit=planets');
                        throw new \App\Game\Core\EndOfRequest();
                    }

                    $afterPlanet = $beforePlanet;
                    $QryUpdate = [];
                    $params = [];

                    if (!empty($name)) {
                        $QryUpdate[] = "`name` = :name";
                        $params[':name'] = $name;
                        $afterPlanet['name'] = $name;
                    }

                    if ($buildings == 'on') {
                        foreach ($reslist['build'] as $ID) {
                            $QryUpdate[] = "`" . $resource[$ID] . "` = '0'";
                            $afterPlanet[$ID] = 0;
                        }
                    }

                    if ($ships == 'on') {
                        foreach ($reslist['fleet'] as $ID) {
                            $QryUpdate[] = "`" . $resource[$ID] . "` = '0'";
                            $afterPlanet[$ID] = 0;
                        }
                    }

                    if ($defenses == 'on') {
                        foreach ($reslist['defense'] as $ID) {
                            $QryUpdate[] = "`" . $resource[$ID] . "` = '0'";
                            $afterPlanet[$ID] = 0;
                        }
                    }

                    if ($c_hangar == 'on') {
                        $QryUpdate[] = "`b_hangar` = 0";
                        $QryUpdate[] = "`b_hangar_plus` = 0";
                        $QryUpdate[] = "`b_hangar_id` = ''";
                        $afterPlanet['b_hangar'] = 0;
                        $afterPlanet['b_hangar_plus'] = 0;
                        $afterPlanet['b_hangar_id'] = '';
                    }

                    if ($c_buildings == 'on') {
                        $QryUpdate[] = "`b_building` = 0";
                        $QryUpdate[] = "`b_building_id` = ''";
                        $afterPlanet['b_building'] = 0;
                        $afterPlanet['b_building_id'] = '';
                    }

                    if (!empty($diameter)) {
                        $QryUpdate[] = "`diameter` = :diameter";
                        $params[':diameter'] = $diameter;
                        $afterPlanet['diameter'] = $diameter;
                    }

                    if (!empty($fields)) {
                        $QryUpdate[] = "`field_max` = :field_max";
                        $params[':field_max'] = $fields;
                        $afterPlanet['field_max'] = $fields;
                    }

                    $QryUpdate2 = [];
                    $params2 = [];
                    $beforePlanet2 = [];
                    $afterPlanet2 = [];
                    $QryUpdate3 = [];
                    $params3 = [];
                    $beforePlanet3 = [];
                    $afterPlanet3 = [];
                    if ($change_pos == 'on') {
                        if (
                            !$this->playerUtil()->checkPosition(
                                $this->universe()->getEmulated(),
                                $galaxy,
                                $system,
                                $planet,
                            ) && $beforePlanet['authlevel'] == AUTH_USR
                        ) {
                            $this->message($LNG['ad_pla_error_planets6'], '?page=accountEditor&edit=planets');
                            throw new \App\Game\Core\EndOfRequest();
                        }

                        if ($beforePlanet['planet_type'] == TYPE_PLANET) {
                            if (
                                !$this->playerUtil()->isPositionFree(
                                    $this->universe()->getEmulated(),
                                    $galaxy,
                                    $system,
                                    $planet,
                                    $beforePlanet['planet_type'],
                                )
                            ) {
                                $this->message($LNG['ad_pla_error_planets3'], '?page=accountEditor&edit=planets');
                                throw new \App\Game\Core\EndOfRequest();
                            }

                            $QryUpdate[] = "`galaxy` = :galaxy";
                            $QryUpdate[] = "`system` = :system";
                            $QryUpdate[] = "`planet` = :planet";
                            $params[':galaxy'] = $galaxy;
                            $params[':system'] = $system;
                            $params[':planet'] = $planet;
                            $afterPlanet['galaxy'] = $galaxy;
                            $afterPlanet['system'] = $system;
                            $afterPlanet['planet'] = $planet;

                            if ($beforePlanet['id_luna'] > 0) {
                                $sql = "SELECT * FROM %%PLANETS%%
                                    WHERE `id` = :id_luna AND `planet_type` = :type_moon;";
                                $beforePlanet2 = $db->selectSingle($sql, [
                                    ':id_luna' => $beforePlanet['id_luna'],
                                    ':type_moon' => TYPE_MOON,
                                ]);
                                $afterPlanet2 = $beforePlanet2;

                                $QryUpdate2[] = "`galaxy` = :galaxy";
                                $QryUpdate2[] = "`system` = :system";
                                $QryUpdate2[] = "`planet` = :planet";
                                $params2[':galaxy'] = $galaxy;
                                $params2[':system'] = $system;
                                $params2[':planet'] = $planet;
                                $params2[':id'] = $beforePlanet2['id'];
                                $afterPlanet2['galaxy'] = $galaxy;
                                $afterPlanet2['system'] = $system;
                                $afterPlanet2['planet'] = $planet;
                            }
                        } else {
                            $sql = "SELECT * FROM %%PLANETS%%
                                WHERE `galaxy` = :galaxy AND `system` = :system
                                AND `planet` = :planet AND `planet_type` = :type_planet
                                AND `universe` = :universe;";
                            $beforePlanet2 = $db->selectSingle($sql, [
                                ':galaxy'   => $galaxy,
                                ':system'   => $system,
                                ':planet'   => $planet,
                                ':universe' => $this->universe()->getEmulated(),
                                ':type_planet' => TYPE_PLANET,
                            ]);
                            $afterPlanet2 = $beforePlanet2;

                            if (empty($beforePlanet2)) {
                                $this->message($LNG['ad_pla_error_planets5'], '?page=accountEditor&edit=planets');
                                throw new \App\Game\Core\EndOfRequest();
                            }

                            if ($beforePlanet2['id_owner'] != $beforePlanet['id_owner']) {
                                $this->message($LNG['ad_pla_error_planets'], '?page=accountEditor&edit=planets');
                                throw new \App\Game\Core\EndOfRequest();
                            }

                            if ($beforePlanet2['id_luna'] > 0) {
                                $this->message($LNG['ad_pla_error_planets4'], '?page=accountEditor&edit=planets');
                                throw new \App\Game\Core\EndOfRequest();
                            }

                            $QryUpdate[] = "`galaxy` = :galaxy";
                            $QryUpdate[] = "`system` = :system";
                            $QryUpdate[] = "`planet` = :planet";
                            $params[':galaxy'] = $galaxy;
                            $params[':system'] = $system;
                            $params[':planet'] = $planet;
                            $afterPlanet['galaxy'] = $galaxy;
                            $afterPlanet['system'] = $system;
                            $afterPlanet['planet'] = $planet;

                            $QryUpdate2[] = "`id_luna` = :id_luna";
                            $params2[':id_luna'] = $beforePlanet['id'];
                            $params2[':id'] = $beforePlanet2['id'];
                            $afterPlanet2['id_luna'] = $beforePlanet['id'];

                            $sql = "SELECT * FROM %%PLANETS%%
                                WHERE `id_luna` = :id AND `planet_type` = :type_planet;";
                            $beforePlanet3 = $db->selectSingle($sql, [
                                ':id' => $beforePlanet['id'],
                                ':type_planet' => TYPE_PLANET,
                            ]);
                            $afterPlanet3 = $beforePlanet3;

                            $QryUpdate3[] = "`id_luna` = :id_luna";
                            $params3[':id_luna'] = 0;
                            $params3[':id'] = $beforePlanet3['id'];
                            $afterPlanet3['id_luna'] = 0;
                        }
                    }

                    if (!empty($QryUpdate)) {
                        $sql = "UPDATE %%PLANETS%% SET ";
                        $sql .= implode(", ", $QryUpdate);
                        $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                        $db->update($sql, array_merge($params, [
                            ':id'       => $id,
                            ':universe' => $this->universe()->getEmulated(),
                        ]));

                        $LOG = new Log($this->db, $this->configs, $this->state, 2);
                        $LOG->target = $id;
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforePlanet;
                        $LOG->new = $afterPlanet;
                        $LOG->save();
                    }
                    if (!empty($beforePlanet2) && !empty($QryUpdate2)) {
                        $sql = "UPDATE %%PLANETS%% SET ";
                        $sql .= implode(", ", $QryUpdate2);
                        $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                        $db->update($sql, array_merge($params2, [
                            ':universe' => $this->universe()->getEmulated(),
                        ]));

                        $LOG = new Log($this->db, $this->configs, $this->state, 2);
                        $LOG->target = $beforePlanet2['id'];
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforePlanet2;
                        $LOG->new = $afterPlanet2;
                        $LOG->save();
                    }
                    if (!empty($beforePlanet3) && !empty($QryUpdate3)) {
                        $sql = "UPDATE %%PLANETS%% SET ";
                        $sql .= implode(", ", $QryUpdate3);
                        $sql .= " WHERE `id` = :id AND `universe` = :universe;";
                        $db->update($sql, array_merge($params3, [
                            ':universe' => $this->universe()->getEmulated(),
                        ]));

                        $LOG = new Log($this->db, $this->configs, $this->state, 2);
                        $LOG->target = $beforePlanet3['id'];
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforePlanet3;
                        $LOG->new = $afterPlanet3;
                        $LOG->save();
                    }

                    $this->message($LNG['ad_pla_succes'], '?page=accountEditor&edit=planets');
                    throw new \App\Game\Core\EndOfRequest();
                }
                return $this->render('admin/AccountEditorPagePlanets.html.twig');

            case 'alliances':
                if ($this->state->requestPost) {
                    $id = $this->http()->_GP('id', 0);
                    $name = $this->http()->_GP('name', '', UTF8_SUPPORT);
                    $changeleader = $this->http()->_GP('changeleader', 0);
                    $tag = $this->http()->_GP('tag', '', UTF8_SUPPORT);
                    $externo = $this->http()->_GP('externo', '', true);
                    $interno = $this->http()->_GP('interno', '', true);
                    $solicitud = $this->http()->_GP('solicitud', '', true);
                    $delete = $this->http()->_GP('delete', '');
                    $delete_u = $this->http()->_GP('delete_u', '');

                    $QryUpdate = [];
                    $params = [];

                    $sql = "SELECT * FROM %%ALLIANCE%% WHERE `id` = :allyId AND `ally_universe` = :universe;";
                    $beforeAlliance = $db->selectSingle($sql, [
                        ':allyId'   => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]);

                    if (!isset($beforeAlliance)) {
                        $this->message($LNG['ad_ally_not_exist'], '?page=accountEditor&edit=alliances');
                        throw new \App\Game\Core\EndOfRequest();
                    }

                    if ($delete == 'on') {
                        $sql = "SELECT `id`, `username`, `ally_id`, `ally_register_time`, `ally_rank_id`
                        FROM %%USERS%% WHERE `ally_id` = :allyId AND `universe` = :universe;";
                        $beforeMembers = $db->select($sql, [
                            ':allyId'   => $id,
                            ':universe' => $this->universe()->getEmulated(),
                        ]);
                        foreach ($beforeMembers as $member) {
                            $afterMember = $member;
                            $sql = "UPDATE %%USERS%% SET `ally_id` = '0', `ally_rank_id` = '0',
                            `ally_register_time` = '0' WHERE `id` = :userId;";
                            $db->update($sql, [
                                ':id' => $member['id'],
                            ]);
                            $afterMember['ally_id'] = 0;
                            $afterMember['ally_rank_id'] = 0;
                            $afterMember['ally_register_time'] = 0;
                            $beforeAlliance['USERS'][$member['id']] = $member;
                            $afterAlliance['USERS'][$member['id']] = $afterMember;
                        }

                        $sql = "SELECT `id_owner`, `id_ally`, `stat_type` FROM %%STATPOINTS%% WHERE `id_ally` = :AllianceID;";
                        $beforeStats = $db->select($sql, [
                            ':AllianceID' => $id,
                        ]);
                        foreach ($beforeStats as $stats) {
                            $afterStats = $beforeStats;
                            $afterStats['id_ally'] = 0;
                            $beforeAlliance['STATPOINTS'][1][] = $stats;
                            $afterAlliance['STATPOINTS'][1][] = $afterStats;
                        }
                        $sql = "UPDATE %%STATPOINTS%% SET id_ally = '0' WHERE id_ally = :AllianceID;";
                        $db->update($sql, [
                            ':AllianceID' => $id,
                        ]);

                        $sql = "SELECT * FROM %%STATPOINTS%% WHERE `id_owner` = :AllianceID AND stat_type = 2;";
                        $beforeStats = $db->selectSingle($sql, [
                            ':AllianceID' => $id,
                        ]);
                        $sql = "DELETE FROM %%STATPOINTS%% WHERE id_owner = :AllianceID AND stat_type = 2;";
                        $db->delete($sql, [
                            ':AllianceID' => $id,
                        ]);
                        $beforeAlliance['STATPOINTS'][2] = $beforeStats;
                        $afterAlliance['STATPOINTS'][2] = [];

                        $sql = "DELETE FROM %%ALLIANCE%% WHERE `id` = :AllianceID;";
                        $db->delete($sql, [
                            ':AllianceID' => $id,
                        ]);

                        $sql = "DELETE FROM %%ALLIANCE_RANK%% WHERE `allianceID` = :AllianceID;";
                        $db->delete($sql, [
                            ':AllianceID' => $id,
                        ]);

                        $sql = "SELECT * FROM %%ALLIANCE_REQUEST%% WHERE `allianceId` = :AllianceID;";
                        $beforeRequest = $db->selectSingle($sql, [
                            ':AllianceID' => $id,
                        ]);
                        $sql = "DELETE FROM %%ALLIANCE_REQUEST%% WHERE `allianceId` = :AllianceID;";
                        $db->delete($sql, [
                            ':AllianceID' => $id,
                        ]);
                        $beforeAlliance['ALLIANCE_REQUEST'] = $beforeRequest;
                        $afterAlliance['ALLIANCE_REQUEST'] = [];

                        $sql = "SELECT * FROM %%DIPLO%% WHERE `owner_1` = :AllianceID OR `owner_2` = :AllianceID;";
                        $beforeDiplos = $db->select($sql, [
                            ':AllianceID' => $id,
                        ]);
                        $sql = "DELETE FROM %%DIPLO%% WHERE `owner_1` = :AllianceID OR `owner_2` = :AllianceID;";
                        $db->delete($sql, [
                            ':AllianceID' => $id,
                        ]);
                        $beforeAlliance['DIPLO'] = $beforeDiplos;
                        $afterAlliance['DIPLO'] = [];

                        $LOG = new Log($this->db, $this->configs, $this->state, 6);
                        $LOG->target = $id;
                        $LOG->universe = $this->universe()->getEmulated();
                        $LOG->old = $beforeAlliance;
                        $LOG->new = $afterAlliance;
                        $LOG->save();

                        $this->message($LNG['ad_ally_succes'], '?page=accountEditor&edit=alliances');
                        throw new \App\Game\Core\EndOfRequest();
                    }

                    $afterAlliance = $beforeAlliance;

                    if ($changeleader > 0) {
                        $sql = "SELECT `id`, `username`, `ally_rank_id` FROM %%USERS%% WHERE `id` = :newLeaderId AND `universe` = :universe;";
                        $beforeNewLeader = $db->selectSingle($sql, [
                            ':newLeaderId' => $changeleader,
                            ':universe'    => $this->universe()->getEmulated(),
                        ]);
                        $afterNewLeader = $beforeNewLeader;

                        if (!isset($beforeNewLeader)) {
                            $this->message($LNG['ad_ally_not_exist2'], '?page=accountEditor&edit=alliances');
                            throw new \App\Game\Core\EndOfRequest();
                        }

                        $sql = "UPDATE %%USERS%% SET `ally_rank_id` = 0 WHERE `id` = :newLeaderId;";
                        $db->update($sql, [
                            ':newLeaderId' => $changeleader,
                        ]);
                        $afterNewLeader['ally_rank_id'] = 0;

                        $QryUpdate[] = "`ally_owner` = :newLeader";
                        $params[':newLeader'] = $changeleader;
                        $afterAlliance['ally_owner'] = $changeleader;
                        $beforeAlliance['USERS'][$changeleader] = $beforeNewLeader;
                        $afterAlliance['USERS'][$changeleader] = $afterNewLeader;
                    }

                    if (!empty($delete_u)) {
                        $sql = "SELECT `id`, `username`, `ally_id`, `ally_register_time`, `ally_rank_id`
                        FROM %%USERS%% WHERE `userId` = :userId AND `ally_id` = :allyId AND `universe` = :universe;";
                        $beforeMember = $db->selectSingle($sql, [
                            ':userId'   => $delete_u,
                            ':allyId'   => $id,
                            ':universe' => $this->universe()->getEmulated(),
                        ]);
                        $afterMember = $beforeMember;

                        if (!isset($beforeMember)) {
                            $this->message($LNG['ad_ally_not_exist3'], '?page=accountEditor&edit=alliances');
                            throw new \App\Game\Core\EndOfRequest();
                        }

                        $sql = "UPDATE %%USERS%% SET `ally_id` = '0', `ally_rank_id` = '0', `ally_register_time` = '0'
                        WHERE `id` = :userId AND `ally_id` = :allyId AND `universe` = :universe;";
                        $db->update($sql, [
                            ':userId'   => $delete_u,
                            ':allyId'   => $id,
                            ':universe' => $this->universe()->getEmulated(),
                        ]);
                        $afterMember['ally_id'] = 0;
                        $afterMember['ally_rank_id'] = 0;
                        $afterMember['ally_register_time'] = 0;

                        $QryUpdate[] = "`ally_members` = ally_members - 1";
                        $afterAlliance['ally_members'] -= 1;
                        $beforeAlliance['USERS'][$delete_u] = $beforeMember;
                        $afterAlliance['USERS'][$delete_u] = $afterMember;
                    }

                    if (!empty($name)) {
                        $QryUpdate[] = "`ally_name` = :ally_name";
                        $params[':ally_name'] = $name;
                        $afterAlliance['ally_name'] = $name;
                    }

                    if (!empty($tag)) {
                        $QryUpdate[] = "`ally_tag` = :ally_tag";
                        $params[':ally_tag'] = $tag;
                        $afterAlliance['ally_tag'] = $tag;
                    }

                    if (!empty($externo)) {
                        $QryUpdate[] = "`ally_description` = :ally_description";
                        $params[':ally_description'] = $externo;
                        $afterAlliance['ally_description'] = $externo;
                    }

                    if (!empty($interno)) {
                        $QryUpdate[] = "`ally_text` = :ally_text";
                        $params[':ally_text'] = $interno;
                        $afterAlliance['ally_text'] = $interno;
                    }

                    if (!empty($solicitud)) {
                        $QryUpdate[] = "`ally_request` = :ally_request";
                        $params[':ally_request'] = $solicitud;
                        $afterAlliance['ally_request'] = $solicitud;
                    }

                    $sql = "UPDATE %%ALLIANCE%% SET ";
                    $sql .= implode(", ", $QryUpdate);
                    $sql .= " WHERE `id` = :id AND `ally_universe` = :universe;";
                    $db->update($sql, array_merge($params, [
                        ':id'       => $id,
                        ':universe' => $this->universe()->getEmulated(),
                    ]));

                    $LOG = new Log($this->db, $this->configs, $this->state, 6);
                    $LOG->target = $id;
                    $LOG->universe = $this->universe()->getEmulated();
                    $LOG->old = $beforeAlliance;
                    $LOG->new = $afterAlliance;
                    $LOG->save();

                    $this->message($LNG['ad_ally_succes'], '?page=accountEditor&edit=alliances');
                    throw new \App\Game\Core\EndOfRequest();
                }
                return $this->render('admin/AccountEditorPageAlliance.html.twig');

            default:
                return $this->render('admin/AccountEditorPageMenu.html.twig');
        }
    }
}
