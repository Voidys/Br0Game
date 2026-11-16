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

use Exception;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\AdvancedStats\AdvancedStatsEnum;

/** Fleet shortcuts from the galaxy view (XHR): spy probes, recyclers and missiles, answered as JSON codes. */
#[\App\Routing\GamePage('game.php', 'fleetAjax')]
#[\App\Routing\GamePage('game.php', 'fleetAjax', uniPrefix: true)]
class FleetAjaxController extends AbstractGameController
{
    public $returnData = [];

    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('ajax');
    }

    private function sendData($Code, $Message)
    {
        $this->returnData['code'] = $Code;
        $this->returnData['mess'] = $Message;
        $this->sendJSON($this->returnData);
    }

    public function show()
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $resource = $this->state->resource;
        $LNG = $this->state->LNG;
        $pricelist = $this->state->pricelist;
        $reslist = $this->state->reslist;
        $UserDeuterium = $PLANET['deuterium'];

        $planetID = $this->http()->_GP('planetID', 0);
        $targetMission = $this->http()->_GP('mission', 0);
        $confirmed = $this->http()->_GP('confirm', 0) == 1;

        $activeSlots = $this->fleetFunctions()->getCurrentFleets($USER['id']);
        $maxSlots = $this->fleetFunctions()->getMaxFleetSlots($USER);

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $this->returnData['slots'] = $activeSlots;

        if (IsVacationMode($USER)) {
            $this->sendData(620, $LNG['fa_vacation_mode_current']);
        }

        if (empty($planetID)) {
            $this->sendData(601, $LNG['fa_planet_not_exist']);
        }

        if ($maxSlots <= $activeSlots) {
            $this->sendData(612, $LNG['fa_no_more_slots']);
        }

        $fleetArray = [];

        $db = $this->db;

        switch ($targetMission) {
            case MISSION_SPY:
            case MISSION_PIRATEBASE_SPY:
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSION_SPY)) {
                    $this->sendData(699, $LNG['sys_module_inactive']);
                }

                $ships = min($USER['spio_anz'], $PLANET[$resource[SHIP_PROBE]]);

                if (empty($ships)) {
                    $this->sendData(611, $LNG['fa_no_spios']);
                }

                $fleetArray = [SHIP_PROBE => $ships];
                $this->returnData['ships'][SHIP_PROBE] = $PLANET[$resource[SHIP_PROBE]] - $ships;
                break;
            case MISSION_RECYCLING:
            case MISSION_PIRATEBASE_RECYCLING:
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSION_RECYCLE)) {
                    $this->sendData(699, $LNG['sys_module_inactive']);
                }

                if ($targetMission == MISSION_RECYCLING) {
                    $sql = "SELECT (der_metal + der_crystal) as sum FROM %%PLANETS%% WHERE id = :planetID AND universe = :universe;";
                } else {
                    $sql = "SELECT (der_metal + der_crystal) as sum FROM %%PIRATE_BASES%% WHERE id = :planetID AND universe = :universe;";
                }
                $totalDebris = $db->selectSingle($sql, [
                    ':planetID' => $planetID,
                    ':universe' => $this->universe()->current(),
                ], 'sum');

                $fleetArray = [];

                foreach ($reslist['ship_type'][SHIP_TYPE_RECYCLER] as $elementID) {
                    $a = $pricelist[$elementID]['capacity'];
                    $shipsNeed = min(ceil($totalDebris / $a), $PLANET[$resource[$elementID]]);
                    $totalDebris -= ($shipsNeed * $a);

                    $fleetArray[$elementID] = $shipsNeed;
                    $this->returnData['ships'][$elementID] = $PLANET[$resource[$elementID]] - $shipsNeed;

                    if ($totalDebris <= 0) {
                        break;
                    }
                }

                if (empty($fleetArray)) {
                    $this->sendData(611, $LNG['fa_no_recyclers']);
                }
                break;
            case MISSION_MISSILE:
                if (!$this->viewHelpers()->isModuleAvailable(MODULE_MISSILEATTACK)) {
                    $this->sendData(699, $LNG['sys_module_inactive']);
                }

                $ships = $PLANET[$resource[INTERPLANETARY_MISSILE]];
                if (empty($ships)) {
                    $this->sendData(611, $LNG['ma_no_missiles']);
                }
                $fleetArray = [INTERPLANETARY_MISSILE => $ships];
                break;
            default:
                $this->sendData(610, $LNG['fa_not_enough_probes']);
                break;
        }

        $fleetArray = array_filter($fleetArray);

        if (empty($fleetArray)) {
            $this->sendData(610, $LNG['fa_not_enough_probes']);
        }

        $targetData = $this->getTargetData($targetMission, $planetID, $USER['id']);

        if (empty($targetData) || ($targetData['destruyed'] != 0 && ($targetMission == MISSION_SPY || $targetMission == MISSION_MISSILE))) {
            $this->sendData(601, $LNG['fa_planet_not_exist']);
        }

        if ($targetMission == MISSION_SPY || $targetMission == MISSION_MISSILE) {
            if ($this->configs->get()->adm_attack == 1 && $targetData['authattack'] > $USER['authlevel']) {
                $this->sendData(619, $LNG['fa_action_not_allowed']);
            }

            if (IsVacationMode($targetData)) {
                $this->sendData(605, $LNG['fa_vacation_mode']);
            }
            $sql = 'SELECT total_points
                FROM %%STATPOINTS%%
                WHERE id_owner = :userId AND stat_type = :statType';

            try {
                $USER += $db->selectSingle($sql, [
                    ':userId' => $USER['id'],
                    ':statType' => 1,
                ]) ?: ['total_points' => 0];
            } catch (Exception $exception) {
            }

            $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($targetData));

            if ($IsNoobProtec['NoobPlayer']) {
                $this->sendData(603, $LNG['fa_week_player']);
            }

            if ($IsNoobProtec['StrongPlayer']) {
                $config = $this->configs->get();
                if (!$config->noob_protection_outlaw) {
                    $this->sendData(604, $LNG['fa_strong_player']);
                }
                if (!$confirmed) {
                    $message = $targetMission == MISSION_MISSILE
                        ? 'fl_attack_confirm_outlaw'
                        : 'fl_spy_confirm_outlaw';
                    $targetPlayerData = $this->playerUtil()->getPlayerById($targetData['id_owner']);
                    $this->sendData(
                        602,
                        $LNG->format(
                            $message,
                            $targetPlayerData['username'],
                        ),
                    );
                }

                if ($targetMission == MISSION_MISSILE) {
                    // Function is used for checks only, the actual fleet will be send in ShowFleetMissilePage
                    $this->sendData(600, '');
                }

                $db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                    ':outlawTime'   => $this->state->runtime->timestamp,
                    ':userId'       => $USER['id'],
                ]);
                $statsHandler->increment($USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
            }

            if ($targetMission == MISSION_MISSILE) {
                // Function is used for checks only, the actual fleet will be send in ShowFleetMissilePage
                $this->sendData(600, '');
            }

            if ($USER['id'] == $targetData['id_owner']) {
                $this->sendData(618, $LNG['fa_not_spy_yourself']);
            }
        }

        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $Distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$targetData['galaxy'], $targetData['system'], $targetData['planet']],
        );
        $SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $USER);
        $Duration = $this->fleetFunctions()->getMissionDuration(MISSION_MISSILE, $SpeedAllMin, $Distance, $SpeedFactor, $USER);
        $consumption = $this->fleetFunctions()->getFleetConsumption($fleetArray, $Duration, $Distance, $USER, $SpeedFactor);

        $UserDeuterium -= $consumption;

        if ($UserDeuterium < 0) {
            $this->sendData(613, $LNG['fa_not_enough_fuel']);
        }

        if ($consumption > $this->fleetFunctions()->getFleetRoom($fleetArray)) {
            $this->sendData(613, $LNG['fa_no_fleetroom']);
        }

        if (connection_aborted()) {
            throw new \App\Game\Core\EndOfRequest();
        }

        $this->returnData['slots']++;

        $fleetResource = [
            901 => 0,
            902 => 0,
            903 => 0,
        ];

        $fleetStartTime = $Duration + $this->state->runtime->timestamp;
        $fleetStayTime = $fleetStartTime;
        $fleetEndTime = $fleetStayTime + $Duration;

        $shipID = array_keys($fleetArray);
        $PLANET['deuterium'] -= $consumption;

        $this->fleetFunctions()->sendFleet(
            $fleetArray,
            $targetMission,
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            $PLANET['planet_type'],
            $targetData['id_owner'],
            $planetID,
            $targetData['galaxy'],
            $targetData['system'],
            $targetData['planet'],
            $targetData['planet_type'],
            $fleetResource,
            $fleetStartTime,
            $fleetStayTime,
            $fleetEndTime,
            0,
            0,
            0,
            $consumption,
        );

        $this->sendData(
            600,
            sprintf(
                '%s %d %s %s %d:%d:%d ...',
                $LNG['fa_sending'],
                (int) floor(array_sum($fleetArray)),
                $LNG['tech'][$shipID[0]],
                $LNG['gl_to'],
                (int) $targetData['galaxy'],
                (int) $targetData['system'],
                (int) $targetData['planet'],
            ),
        );
    }

    private function getTargetData(int $mission, int $planetID, int $userId): array|bool
    {
        $db = $this->db;
        if ($mission == MISSION_PIRATEBASE_SPY || $mission == MISSION_PIRATEBASE_RECYCLING) {
            $config = $this->configs->get();
            $planet = $config->max_planets + 3;
            $sql = "SELECT `id_owner`, `galaxy`, `system`, $planet as planet, `planet_type`
                FROM %%PIRATE_BASES%%
                WHERE `id` = :planetID AND `id_owner` = :userId AND `universe` = :universe;";

            return $db->selectSingle($sql, [
                ':planetID' => $planetID,
                ':userId'   => $userId,
                ':universe' => $this->universe()->current(),
            ]) ?: [];
        } else {
            $sql = "SELECT p.`id_owner`, p.`galaxy`, p.`system`, p.`planet`, p.`planet_type`, p.`destruyed`, u.`id`, 
                    stat.`total_points`, u.`onlinetime`, u.`urlaubs_modus`, u.`banaday`, u.`authattack`, u.`rebuild_protection`, 
                    u.`rebuild_protection_attackers`, u.`outlaw_time`
                FROM %%PLANETS%% p
                INNER JOIN %%USERS%% u ON p.`id_owner` = u.`id`
                LEFT JOIN %%STATPOINTS%% as stat ON stat.`id_owner` = u.`id` AND stat.`stat_type` = '1'
                WHERE p.`id` = :planetID AND p.`universe` = :universe;";
        }

        return $db->selectSingle($sql, [
            ':planetID' => $planetID,
            ':universe' => $this->universe()->current(),
        ]);
    }
}
