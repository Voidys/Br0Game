<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
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
use App\Game\Core\StoredData;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\BuildFunctions;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/** Repair dock: repairs ships from the wreck field of the current planet. */
#[\App\Routing\GamePage('game.php', 'repairdock')]
#[\App\Routing\GamePage('game.php', 'repairdock', uniPrefix: true)]
class RepairdockController extends AbstractGameController
{
    public static $requireModule = 'MODULE_REPAIR_DOCK';

    public static $defaultController = 'show';

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $db = $this->db;

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
        $wreckfield = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);

        if (empty($wreckfield) || (empty($wreckfield['ships']) && empty($wreckfield['repair_order']))) {
            $this->printMessage($LNG['bd_repairdock_empty']);
        }

        $ships = $this->fleetFunctions()->unserialize($wreckfield['ships']);
        $buildTodo = $this->http()->_GP('fmenge', []);
        $action = $this->http()->_GP('cmd', '');

        $ElementQueue = !empty($wreckfield['repair_order']) ? StoredData::decode($wreckfield['repair_order']) : [];
        $buildList = [];
        if (!empty($action) && $action == 'deploy' && $wreckfield['repair_order_end'] <= $this->state->runtime->timestamp) {
            $db->startTransaction();
            $sql = "SELECT * FROM %%PLANET_WRECKFIELD%%
                WHERE `planetID` = :planetID FOR UPDATE;";
            // Re-read the order under the lock: a parallel deploy request may already have consumed it.
            $locked = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);
            if (
                empty($locked) || empty($locked['repair_order'])
                || $locked['repair_order_end'] > $this->state->runtime->timestamp
            ) {
                $db->commit();
                $this->redirectTo('game?page=repairdock');
            }
            $ships = $this->fleetFunctions()->unserialize($locked['ships']);
            $ElementQueue = StoredData::decode($locked['repair_order']);
            $queuedShips = $ships;

            $repaired = [];
            $params = [
                ':planetID' => $PLANET['id'],
            ];
            foreach ($ElementQueue as $elementID => $amount) {
                $repaired[] = '`' . $resource[$elementID] . '` = ' . $resource[$elementID] . ' + :' . $resource[$elementID];
                $params[':' . $resource[$elementID]] = $amount;
                $statsHandler->increment($USER['id'], AdvancedStatsEnum::getRepaired($elementID), $amount);
            }
            $ships = $this->remainingWrecks($queuedShips, $ElementQueue, $PLANET[$resource[REPAIR_DOCK]]);

            if (!empty($repaired)) {
                $sql = "UPDATE %%PLANETS%% SET " . implode(', ', $repaired) . " WHERE id = :planetID;";
                $db->update($sql, $params);

                $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `ships` = :ships, `repair_order` = '', `repair_order_start` = 0, `repair_order_end` = 0
                    WHERE `planetID` = :planetID;";
                $db->update($sql, [
                    ':ships' => $this->fleetFunctions()->serialize($ships),
                    ':planetID' => $PLANET['id'],
                ]);
            }
            $db->commit();
            $this->printMessage($LNG['bd_repairdock_deploy'], [[
                'label' => $LNG['bd_continue'],
                'url'   => 'game?page=repairdock',
            ]]);
        } elseif (empty($ElementQueue) && !empty($buildTodo) && array_sum($buildTodo) > 0 && $USER['urlaubs_modus'] == 0) {
            $db->startTransaction();
            $sql = "SELECT * FROM %%PLANET_WRECKFIELD%%
                WHERE `planetID` = :planetID FOR UPDATE;";
            $locked = $db->selectSingle($sql, [':planetID' => $PLANET['id']]);
            if (empty($locked) || !empty($locked['repair_order'])) {
                // A parallel request already queued an order.
                $db->commit();
                $this->redirectTo('game?page=repairdock');
            }
            $ships = $this->fleetFunctions()->unserialize($locked['ships']);
            $wreckfield = $locked;

            $QueueTime = 0;
            foreach ($buildTodo as $element => $amount) {
                if (!empty($ships[$element])) {
                    $amount = is_numeric($amount) ? round($amount) : 0;
                    if ($amount > 0) {
                        $ElementQueue[$element] = max(0, min($amount, floor($ships[$element] * $this->getRepairRate($PLANET[$resource[REPAIR_DOCK]]))));
                        $ElementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $element);
                        $QueueTime += $ElementTime * $ElementQueue[$element];
                    }
                }
            }
            $ElementQueue = array_filter($ElementQueue);

            $min = max($this->configs->get()->min_build_time, 1800 - ($this->state->runtime->timestamp - $wreckfield['created']));
            $QueueTime = max($min, min($QueueTime, TIME_12_HOURS)); //Repair duration: min Creation + 30m, max 12h

            $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `repair_order` = :repair_order, `repair_order_start` = :start_time, `repair_order_end` = :end_time
                WHERE `planetID` = :planetID;";
            $db->update($sql, [
                ':repair_order' => StoredData::encode(array_filter($ElementQueue)),
                ':start_time' => $this->state->runtime->timestamp,
                ':end_time' => $this->state->runtime->timestamp + $QueueTime,
                ':planetID' => $PLANET['id'],
            ]);

            $buildList = [
                'ships' => $ElementQueue,
                'time' => $this->state->runtime->timestamp,
                'resttime' => $QueueTime,
                'endtime' => $this->state->runtime->timestamp + $QueueTime,
                'pretty_end_time' => $this->viewHelpers()->prettyTime($QueueTime),
            ];

            $db->commit();
            $this->redirectTo('game?page=repairdock');
        } elseif (!empty($ElementQueue)) {
            $buildList = [
                'ships' => $ElementQueue,
                'time' => $this->state->runtime->timestamp - $wreckfield['repair_order_start'],
                'resttime'  => $USER['urlaubs_modus'] ? ($wreckfield['repair_order_end'] - $USER['urlaubs_start']) : ($wreckfield['repair_order_end'] - $this->state->runtime->timestamp),
                'endtime'   => $this->viewHelpers()->formatDate('U', $wreckfield['repair_order_end'], $USER['timezone']),
                'pretty_end_time' => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $wreckfield['repair_order_end'], $USER['timezone']),
            ];

            $ships = $this->remainingWrecks($ships, $ElementQueue, $PLANET[$resource[REPAIR_DOCK]]);
        }

        $elementIDs = $reslist['fleet'];
        $elementList = [];
        foreach ($elementIDs as $elementID) {
            if (!isset($ships[$elementID])) {
                continue;
            }

            $elementTime = $this->buildFunctions()->getBuildingTime($USER, $PLANET, $elementID);
            $maxBuildable = floor($ships[$elementID] * $this->getRepairRate($PLANET[$resource[REPAIR_DOCK]]));

            $elementList[$elementID] = [
                'id' => $elementID,
                'available' => $PLANET[$resource[$elementID]],
                'elementTime' => $elementTime,
                'maxBuildable' => floatToString($maxBuildable),
                'wrecks' => $ships[$elementID],
            ];
        }
        $SolarEnergy = round((($PLANET['temp_max'] + 160) / 6) * $this->configs->get()->energy_multiplier, 1);

        $busy = !empty($wreckfield['repair_order']);

        $this->addScript('repairdock.js');

        $this->assign([
            'repdock_level' => $PLANET[$resource[REPAIR_DOCK]],
            'umode'         => $USER['urlaubs_modus'],
            'wftime'        => $wreckfield['created'] + TIME_72_HOURS - $this->state->runtime->timestamp,
            'busy'          => $busy,
            'elementList'   => $elementList,
            'List'          => $buildList,
            'SolarEnergy'   => $SolarEnergy,
            'repairRate'    => $this->getRepairRate($PLANET[$resource[REPAIR_DOCK]]) * 100,
        ]);

        return $this->render('game/page.repairdock.default.html.twig');
    }

    /**
     * Wrecks left after an order. An order of $amount ships uses up ceil($amount / rate) wrecks (rounded up, so
     * splitting an order into many small ones can never repair more than the rate share of the wrecks).
     */
    private function remainingWrecks(array $ships, array $queue, $repairDockLvl): array
    {
        $rate = $this->getRepairRate($repairDockLvl);
        foreach ($queue as $elementID => $amount) {
            if (empty($ships[$elementID])) {
                continue;
            }
            if (floor($ships[$elementID] * $rate) == $amount) {
                $ships[$elementID] = 0;
            } else {
                $ships[$elementID] -= ceil(round($amount / $rate, 6));
            }
            if ($ships[$elementID] <= 0) {
                unset($ships[$elementID]);
            }
        }

        return $ships;
    }

    /**
     * Share of the wrecks that can be repaired: (24 + level^1.5) %, capped at 100% (reached at dock level 18),
     * so no more ships than wrecks can ever be repaired.
     */
    private function getRepairRate($repairDockLvl): float
    {
        return min(1.0, (24 + pow($repairDockLvl, 1.5)) / 100);
    }
}
