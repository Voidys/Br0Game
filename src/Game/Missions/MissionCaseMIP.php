<?php

/*
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
/**
 * Calculates missile attacks
 *
 */

namespace App\Game\Missions;

use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

class MissionCaseMIP extends MissionFunctions implements MissionInterface
{
    public function __construct(array $Fleet, \App\Game\Core\Database $db, \App\Game\Core\ConfigRegistry $configs, \App\Game\Core\Singleton $state)
    {
        parent::__construct($db, $configs, $state);
        $this->_fleet	= $Fleet;
    }

    public function TargetEvent(): void
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $db	= $this->db;

        $sqlFields	= [];
        $elementIDs	= array_merge($reslist['defense'], $reslist['missile']);

        foreach ($elementIDs as $elementID) {
            $sqlFields[]	= 'p.`' . $resource[$elementID] . '`';
        }

        $sql = "SELECT us.`lang`, u.`defence_tech`,
            p.`id`, p.`name`, p.`id_owner`, " . implode(', ', $sqlFields) . "
            FROM %%PLANETS%% p
            INNER JOIN %%USERS%% u ON p.`id_owner` = u.`id`
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id`
            WHERE p.`id` = :planetId;";

        $targetData	= $db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['end_id']
        ]);

        // kill fleet if target planet deleted
        if ($targetData == false) {
            $fleetArray = $this->fleetFunctions()->unserialize($this->_fleet['ships']);
            $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], $fleetArray);
            $this->KillFleet();
            return;
        }

        // vacation mode of the target owner: hostile missions turn around
        if ($this->returnIfTargetInVacation($targetData['id_owner'])) {
            return;
        }

        // The interceptor column may be missing from the query (no element class) or NULL: always an integer
        $targetData[$resource[INTERCEPTOR_MISSILE]] = (int) ($targetData[$resource[INTERCEPTOR_MISSILE]] ?? 0);

        $sql = 'SELECT us.`lang`, u.`military_tech` FROM %%USERS%% u 
            INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id` 
            WHERE u.`id` = :userId;';
        $senderData	= $db->selectSingle($sql, [
            ':userId'	=> $this->_fleet['owner']
        ]);

        if (
            !in_array($this->_fleet['missile_target'], array_merge($reslist['defense'], $reslist['missile']))
            || $this->_fleet['missile_target'] == INTERCEPTOR_MISSILE
            || $this->_fleet['missile_target'] == 0
        ) {
            $primaryTarget	= MISSILE_LAUNCHER;
        } else {
            $primaryTarget	= $this->_fleet['missile_target'];
        }

        $targetDefensive    = [];

        foreach ($elementIDs as $elementID) {
            $targetDefensive[$elementID]	= $targetData[$resource[$elementID]];
        }

        unset($targetDefensive[INTERCEPTOR_MISSILE]);

        $senderData['LNG']	= $this->getLanguage($senderData['lang']);
        $targetData['LNG']	= $this->getLanguage($targetData['lang']);

        $senderData['MSG'] = false;
        $targetData['MSG'] = false;

        if ($targetData[$resource[INTERCEPTOR_MISSILE]] >= $this->_fleet['ship_amount']) {
            $senderData['MSG'] 	= $senderData['LNG']['sys_irak_no_att'];
            $targetData['MSG']	= $targetData['LNG']['sys_irak_no_att'];
            $sql = 'UPDATE %%PLANETS%% SET ' . $resource[INTERCEPTOR_MISSILE] . ' = ' . $resource[INTERCEPTOR_MISSILE] . ' - :amount 
                WHERE `id` = :planetId;';

            $db->update($sql, [
                ':amount'	=> $this->_fleet['ship_amount'],
                ':planetId'	=> $targetData['id']
            ]);
            $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['target_owner'], $this->_fleet['owner'], INTERPLANETARY_MISSILE, $this->_fleet['ship_amount'], false);
            $this->statsHandler->updateLostAdvancedStats($this->_fleet['target_owner'], [INTERCEPTOR_MISSILE => $this->_fleet['ship_amount']]);
        } else {
            if ($targetData[$resource[INTERCEPTOR_MISSILE]] > 0) {
                $sql	= 'UPDATE %%PLANETS%% SET ' . $resource[INTERCEPTOR_MISSILE] . ' = :amount 
                    WHERE `id` = :planetId;';

                $db->update($sql, [
                    ':amount'	=> 0,
                    ':planetId'	=> $targetData['id']
                ]);

                $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['target_owner'], $this->_fleet['owner'], INTERPLANETARY_MISSILE, $targetData[$resource[INTERCEPTOR_MISSILE]], false);
                $this->statsHandler->updateLostAdvancedStats($this->_fleet['target_owner'], [INTERCEPTOR_MISSILE => $targetData[$resource[INTERCEPTOR_MISSILE]]]);
            }

            $targetDefensive = array_filter($targetDefensive);

            if (!empty($targetDefensive)) {
                $result   	= $this->calculateMIPAttack(
                    $targetData["defence_tech"],
                    $senderData["military_tech"],
                    $this->_fleet['ship_amount'],
                    $targetDefensive,
                    $primaryTarget,
                    $targetData[$resource[INTERCEPTOR_MISSILE]]
                );

                $result		= array_filter($result);


                $senderData['MSG'] 	= sprintf($senderData['LNG']['sys_irak_def'], $targetData[$resource[INTERCEPTOR_MISSILE]]) . '<br><br>';
                $targetData['MSG']	= sprintf($targetData['LNG']['sys_irak_def'], $targetData[$resource[INTERCEPTOR_MISSILE]]) . '<br><br>';

                ksort($result, SORT_NUMERIC);

                foreach ($result as $Element => $destroy) {
                    $senderData['MSG'] 	.= sprintf('%s (- %d)<br>', $senderData['LNG']['tech'][$Element], $destroy);
                    $targetData['MSG']	.= sprintf('%s (- %d)<br>', $targetData['LNG']['tech'][$Element], $destroy);

                    $sql	= 'UPDATE %%PLANETS%% SET ' . $resource[$Element] . ' = ' . $resource[$Element] . ' - :amount 
                        WHERE `id` = :planetId;';
                    $db->update($sql, [
                        ':planetId' => $targetData['id'],
                        ':amount'	=> $destroy
                    ]);
                    $this->statsHandler->updateDestroyedAdvancedStats($this->_fleet['owner'], $this->_fleet['target_owner'], $Element, $destroy);
                }
                $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], [INTERPLANETARY_MISSILE => $this->_fleet['ship_amount'] - $targetData[$resource[INTERCEPTOR_MISSILE]]]);
            } else {
                $this->statsHandler->updateLostAdvancedStats($this->_fleet['owner'], [INTERPLANETARY_MISSILE => $this->_fleet['ship_amount']]);
                $senderData['MSG'] 	= $senderData['LNG']['sys_irak_no_def'];
                $targetData['MSG']	= $targetData['LNG']['sys_irak_no_def'];
            }
        }

        $sql = 'SELECT `name` FROM %%PLANETS%% WHERE `id` = :planetId;';
        $planetName	= $this->db->selectSingle($sql, [
            ':planetId'	=> $this->_fleet['start_id'],
        ], 'name');

        $ownerLink			= $planetName . " " . GetStartAddressLink($this->_fleet);
        $targetLink 		= $targetData['name'] . " " . GetTargetAddressLink($this->_fleet);
        $senderData['MSG'] 	= sprintf($senderData['LNG']['sys_irak_mess'], $this->_fleet['ship_amount'], $ownerLink, $targetLink) . $senderData['MSG'];
        $targetData['MSG']	= sprintf($targetData['LNG']['sys_irak_mess'], $this->_fleet['ship_amount'], $ownerLink, $targetLink) . $targetData['MSG'];

        $this->playerUtil()->sendMessage(
            $this->_fleet['owner'],
            0,
            $senderData['LNG']['sys_mess_tower'],
            MESSAGE_TYPE_BATTLE_REPORT,
            $senderData['LNG']['sys_irak_subject'],
            $senderData['MSG'],
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );

        $this->playerUtil()->sendMessage(
            $this->_fleet['target_owner'],
            0,
            $targetData['LNG']['sys_mess_tower'],
            MESSAGE_TYPE_BATTLE_REPORT,
            $targetData['LNG']['sys_irak_subject'],
            $targetData['MSG'],
            $this->_fleet['arrival_time'],
            1,
            $this->_fleet['universe'],
            $this->_fleet['end_id']
        );

        $this->KillFleet();
    }

    public function EndStayEvent(): void
    {
        return;
    }

    public function ReturnEvent(): void
    {
        return;
    }

    private function calculateMIPAttack(int $TargetDefTech, int $OwnerAttTech, int $missiles, array $targetDefensive, 
        int $firstTarget, int $defenseMissles): array
    {
        $pricelist =& $this->state->pricelist;
        $CombatCaps =& $this->state->CombatCaps;
        $destroyShips = [];
        $countMissles = $missiles - $defenseMissles;

        if ($countMissles == 0) {
            return $destroyShips;
        }

        $totalAttack = $countMissles * $CombatCaps[INTERPLANETARY_MISSILE]['attack'] * (1 +  0.1 * $OwnerAttTech);

        // Select primary target, if exists
        if (isset($targetDefensive[$firstTarget])) {
            $firstTargetData	= [$firstTarget => $targetDefensive[$firstTarget]];
            unset($targetDefensive[$firstTarget]);
            $targetDefensive	= $firstTargetData + $targetDefensive;
        }

        foreach ($targetDefensive as $element => $count) {
            if ($element == 0) {
                throw new \Exception("Unknown error. Please report this error at https://github.com/Voidys/Br0Game/issues. Debuginforations:<br><br>".json_encode([$TargetDefTech, $OwnerAttTech, $missiles, $targetDefensive, $firstTarget, $defenseMissles]));
            }
            $elementStructurePoints = ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]) * (1 + 0.1 * $TargetDefTech) / 10;
            $destroyCount           = floor($totalAttack / $elementStructurePoints);
            $destroyCount           = min($destroyCount, $count);
            $totalAttack           -= $destroyCount * $elementStructurePoints;

            $destroyShips[$element]	= $destroyCount;
            if ($totalAttack <= 0) {
                return $destroyShips;
            }
        }

        return $destroyShips;
    }

}
