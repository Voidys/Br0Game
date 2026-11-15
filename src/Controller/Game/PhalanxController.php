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
use App\Game\Core\FlyingFleetsTable;
use App\Game\Core\HTTP;
use App\Game\Core\Universe;

/** Sensor phalanx: fleet movements around a scanned planet, costs deuterium. */
#[\App\Routing\GamePage('game.php', 'phalanx')]
#[\App\Routing\GamePage('game.php', 'phalanx', uniPrefix: true)]
class PhalanxController extends AbstractGameController
{
    public static $requireModule = 'MODULE_PHALANX';

    public function initialize(): void {}

    public function show(): Response
    {
        $PLANET = & $this->state->PLANET;
        $LNG = & $this->state->LNG;
        $resource = & $this->state->resource;
        $USER = & $this->state->USER;
        $this->setWindow('popup');
        $this->addScript('phalanx.js');

        $Galaxy 			= $this->http()->_GP('galaxy', 0);
        $System 			= $this->http()->_GP('system', 0);
        $Planet 			= $this->http()->_GP('planet', 0);

        if ($PLANET[$resource[903]] < PHALANX_DEUTERIUM) {
            $this->printMessage($LNG['px_no_deuterium']);
        }

        $db = $this->db;
        $sql = "UPDATE %%PLANETS%% SET deuterium = deuterium - :phalanxDeuterium WHERE id = :planetID;";
        $db->update($sql, [
            ':phalanxDeuterium'	=> PHALANX_DEUTERIUM,
            ':planetID'			=> $PLANET['id'],
        ]);

        $sql = "SELECT id, name, id_owner FROM %%PLANETS%% WHERE universe = :universe
		AND galaxy = :galaxy AND system = :system AND planet = :planet AND :type;";

        $TargetInfo = $db->selectSingle($sql, [
            ':universe'	=> $this->universe()->current(),
            ':galaxy'	=> $Galaxy,
            ':system'	=> $System,
            ':planet'	=> $Planet,
            ':type'		=> 1,
        ]);

        $sql = "SELECT u.id, s.total_points, u.onlinetime, u.banaday, u.rebuild_protection, u.rebuild_protection_attackers, u.outlaw_time
            FROM %%USERS%% u 
            LEFT JOIN %%STATPOINTS%% s ON s.id_owner = u.id AND s.stat_type = :statTypeUser 
            WHERE u.universe = :universe AND id = :id_owner;";

        $TargetUser = $db->selectSingle($sql, [
            ':statTypeUser'     => 1,
            ':universe'			=> $this->universe()->current(),
            ':id_owner'			=> $TargetInfo['id_owner'],
        ]);

        if (!$this->fleetFunctions()->allowPhalanx($Galaxy, $System, $TargetUser)) {
            $this->printMessage($LNG['px_out_of_range']);
        }
        if (empty($TargetInfo)) {
            $this->printMessage($LNG['px_out_of_range']);
        }


        $fleetTableObj = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $fleetTableObj->setPhalanxMode();
        $fleetTableObj->setUser($TargetInfo['id_owner']);
        $fleetTableObj->setPlanet($TargetInfo['id']);
        $fleetTable	=  $fleetTableObj->renderTable(true);

        $this->assign([
            'galaxy'  		=> $Galaxy,
            'system'  		=> $System,
            'planet'   		=> $Planet,
            'name'    		=> $TargetInfo['name'],
            'fleetTable'	=> $fleetTable,
            'colors'		=> $USER['colors'],
            'servertime'    => $this->viewHelpers()->formatDate("M D d H:i:s", $this->state->runtime->timestamp, $USER['timezone']),
        ]);

        return $this->render('game/page.phalanx.default.html.twig');
    }
}
