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

use Symfony\Component\HttpFoundation\Response;
use App\Routing\GameRoute;
use App\Game\Core\FlyingFleetsTable;

/** Sensor phalanx: fleet movements around a scanned planet, costs deuterium. */
#[\App\Controller\RequireModule('MODULE_PHALANX')]
class PhalanxController extends AbstractGameController
{
    public function initialize(): void {}

    /**
     * Sensor phalanx scan of the target coordinates (request: galaxy, system, planet). Needs PHALANX_DEUTERIUM deuterium on the current moon,
     * which is deducted; the target must be within the phalanx range (FleetFunctions::allowPhalanx) and exist. Lists the target planet's fleet
     * movements.
     */
    #[GameRoute('game', 'game/phalanx', 'phalanx')]
    public function show(): Response
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $this->setWindow('popup');
        $this->addScript('phalanx.js');

        $Galaxy 			= $this->input()->int('galaxy');
        $System 			= $this->input()->int('system');
        $Planet 			= $this->input()->int('planet');

        if ($PLANET[$this->elements()->column(RESOURCE_DEUT)] < PHALANX_DEUTERIUM) {
            return $this->messageResponse($LNG['px_no_deuterium']);
        }

        $db = $this->db;

        $sql = "SELECT id, name, id_owner FROM %%PLANETS%% WHERE universe = :universe
		AND galaxy = :galaxy AND system = :system AND planet = :planet AND planet_type = :type;";

        $TargetInfo = $db->selectSingle($sql, [
            ':universe'	=> $this->universe()->current(),
            ':galaxy'	=> $Galaxy,
            ':system'	=> $System,
            ':planet'	=> $Planet,
            ':type'		=> 1,
        ]);

        if (empty($TargetInfo)) {
            // no planet at these coordinates (also: no coordinates given)
            return $this->messageResponse($LNG['px_out_of_range']);
        }

        $sql = "SELECT u.id, s.total_points, u.onlinetime, u.banaday, u.rebuild_protection, u.rebuild_protection_attackers, u.outlaw_time
            FROM %%USERS%% u 
            LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = :statTypeUser 
            WHERE u.universe = :universe AND id = :id_owner;";

        $TargetUser = $db->selectSingle($sql, [
            ':statTypeUser'     => 1,
            ':universe'			=> $this->universe()->current(),
            ':id_owner'			=> $TargetInfo['id_owner'],
        ]);

        if (empty($TargetUser) || !$this->fleetFunctions()->allowPhalanx($Galaxy, $System, $TargetUser)) {
            return $this->messageResponse($LNG['px_out_of_range']);
        }

        // the scan is only paid when it is possible; the guard keeps parallel scans from spending the same deuterium twice
        $db->update('UPDATE %%PLANETS%% SET deuterium = deuterium - :cost WHERE id = :planetID AND deuterium >= :cost;', [
            ':cost' => PHALANX_DEUTERIUM,
            ':planetID' => $PLANET['id'],
        ]);
        if ($db->rowCount() !== 1) {
            return $this->messageResponse($LNG['px_no_deuterium']);
        }

        $fleetTableObj = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $fleetTableObj->setPhalanxMode();
        $fleetTableObj->setUser($TargetInfo['id_owner']);
        $fleetTableObj->setPlanet($TargetInfo['id']);
        $fleetTable	=  $fleetTableObj->renderTable(true);

        return $this->render('game/page.phalanx.default.html.twig', [
            'galaxy'  		=> $Galaxy,
            'system'  		=> $System,
            'planet'   		=> $Planet,
            'name'    		=> $TargetInfo['name'],
            'fleetTable'	=> $fleetTable,
            'colors'		=> $USER['colors'],
            'servertime'    => $this->viewHelpers()->formatDate("M D d H:i:s", $this->state->runtime->timestamp, $USER['timezone']),
        ]);
    }
}
