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
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Log;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Gives resources and dark matter to the players (or planets/moons) of the universe.
 */
#[\App\Routing\GamePage('admin', 'giveaway')]
class GiveawayController extends AbstractAdminController
{
    /**
     * Gift form and action (action=send). Gives the entered amounts (element_<id>: resources, dark matter, buildings, research, ships,
     * defense) in addition to the current values: to all planets and/or moons of the universe (planet, moon), optionally only home
     * planets (mainplanet) and only players active within INACTIVE seconds (no_inactive). Writes an admin log entry (type 4).
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;
        $action = $this->http()->_GP('action', '');
        if ($action == 'send') {
            $planet = $this->http()->_GP('planet', 0);
            $moon = $this->http()->_GP('moon', 0);
            $mainplanet = $this->http()->_GP('mainplanet', 0);
            $no_inactive = $this->http()->_GP('no_inactive', 0);

            if (!$planet && !$moon) {
                $this->message($LNG['ga_selectplanettype']);
            }

            $planetIN = [];

            if ($planet) {
                $planetIN[] = "'1'";
            }

            if ($moon) {
                $planetIN[] = "'3'";
            }

            $data = [];
            $params = [':universe' => $this->universe()->getEmulated()];

            $DataIDs = array_merge(
                $e->resourceIds(1),
                $e->resourceIds(3),
                $e->ids('build'),
                $e->ids('tech'),
                $e->ids('fleet'),
                $e->ids('defense'),
            );

            $logOld = [];
            $logNew = [];

            foreach ($DataIDs as $ID) {
                $amount = max(0, round($this->http()->_GP('element_' . $ID, 0.0)));
                $data[] = $e->column($ID) . " = " . $e->column($ID) . " + :element" . $ID;
                $params[':element' . $ID] = sprintf('%.0f', $amount);

                $logOld[$ID] = 0;
                $logNew[$ID] = $amount;
            }

            $SQL = "UPDATE %%PLANETS%% p INNER JOIN %%USERS%% u ON p.id_owner = u.id";

            if ($mainplanet == true) {
                $SQL .= " AND p.id = u.id_planet";
            }

            if ($no_inactive == true) {
                $SQL .= " AND u.onlinetime > :activeSince";
                $params[':activeSince'] = $this->state->runtime->timestamp - INACTIVE;
            }

            $SQL .= " SET " . implode(', ', $data) . " WHERE p.universe = :universe"
                . " AND p.planet_type IN (" . implode(',', $planetIN) . ")";
            $db->update($SQL, $params);
            $LOG = new Log($this->db, $this->configs, $this->state, 4);
            $LOG->target = 0;
            $LOG->old = $logOld;
            $LOG->new = $logNew;
            $LOG->save();
            $this->message($LNG['ga_success']);
        }

        $this->assign([
            'reslist'      => $e->groupMap() + ['resstype' => $e->resourceTypes()],
        ]);
        return $this->render('admin/giveaway.html.twig');
    }
}
