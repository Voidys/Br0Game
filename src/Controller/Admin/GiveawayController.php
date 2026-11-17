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
use App\Game\Core\Log;
use App\Game\Core\Universe;
use App\Routing\GameRoute;

/**
 * Gives resources and dark matter to the players (or planets/moons) of the universe.
 */
class GiveawayController extends AbstractAdminController
{
    /** Gift form: the amount fields per resource, dark matter, building, research, ship and defense. */
    #[GameRoute('admin', 'admin/giveaway', 'giveaway')]
    public function show(): Response
    {
        return $this->render('admin/giveaway.html.twig', [
            'reslist'      => $this->elements()->groupMap() + ['resstype' => $this->elements()->resourceTypes()],
        ]);
    }

    /**
     * Gives the entered amounts (element_<id>: resources, dark matter, buildings, research, ships,
     * defense) in addition to the current values: to all planets and/or moons of the universe (planet, moon), optionally only home
     * planets (mainplanet) and only players active within INACTIVE seconds (no_inactive). Writes an admin log entry (type 4).
     */
    #[GameRoute('admin', 'admin/giveaway', 'giveaway', 'send', ['POST'])]
    public function send(): Response
    {
        $LNG = $this->state->LNG;
        $e = $this->elements();
        $db = $this->db;
        $planet = $this->input()->int('planet');
        $moon = $this->input()->int('moon');
        $mainplanet = $this->input()->int('mainplanet');
        $no_inactive = $this->input()->int('no_inactive');

        if (!$planet && !$moon) {
            return $this->messageResponse($LNG['ga_selectplanettype']);
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
            $amount = max(0, round($this->input()->float('element_' . $ID)));
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
        return $this->messageResponse($LNG['ga_success']);
    }
}
