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
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/** Interplanetary missile attack: validates the target and launches the missiles. */
#[\App\Routing\GamePage('game.php', 'fleetMissile')]
#[\App\Routing\GamePage('game.php', 'fleetMissile', uniPrefix: true)]
class FleetMissileController extends AbstractGameController
{
    public static $requireModule = 'MODULE_MISSILEATTACK';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Interplanetary missile launch (request: galaxy, system, planet, type = planet type of the target, SendMI = number of missiles, Target
     * = primary target defense id). Validates silo level, missile range, protections and counts, then creates the missile fleet event and
     * deducts the missiles and ends with a message page. Direct GET visits with empty input end in the error message (the galaxy form POSTs the launch).
     */
    public function show()
    {
        // Planet selector keeps page=fleetMissile and only adds cp=. A GET then
        // re-enters this action with empty coords/SendMI and always hits the
        // error template. Galaxy already POSTs the launch form.
        if (($this->state->requestServer['REQUEST_METHOD'] ?? '') !== 'POST') {
            $this->redirectTo('game?page=galaxy');
        }

        $USER = & $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $reslist = $this->state->reslist;
        $resource = $this->state->resource;
        $missileCount       = $PLANET['interplanetary_misil'];
        $targetGalaxy       = $this->http()->_GP('galaxy', 0);
        $targetSystem       = $this->http()->_GP('system', 0);
        $targetPlanet       = $this->http()->_GP('planet', 0);
        $targetType         = $this->http()->_GP('type', 0);
        $anz                = min($this->http()->_GP('SendMI', 0), $missileCount);
        $primaryTarget      = $this->http()->_GP('Target', 0);

        $db = $this->db;

        $sql = "SELECT p.`id` as planetID, p.`id_owner`, p.`galaxy`, p.`system`, p.`planet`,
                u.`id`, u.`onlinetime`, u.`banaday`, u.`urlaubs_modus`, u.`authattack`, u.`rebuild_protection`, u.`rebuild_protection_attackers`, u.`outlaw_time`,
                s.`total_points`
            FROM %%PLANETS%% p
            JOIN %%USERS%% u ON u.`id` = p.`id_owner`
            JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = 1
            WHERE p.`universe` = :universe AND p.`galaxy` = :targetGalaxy
              AND p.`system` = :targetSystem AND p.`planet` = :targetPlanet AND p.`planet_type` = :targetType;";

        $target = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':targetGalaxy' => $targetGalaxy,
            ':targetSystem' => $targetSystem,
            ':targetPlanet' => $targetPlanet,
            ':targetType'   => $targetType,
        ]);

        $error = "";

        if (IsVacationMode($USER)) {
            $error = $LNG['fl_vacation_mode_active'];
        } elseif ($PLANET['silo'] < 4) {
            $error = $LNG['ma_silo_level'];
        } elseif ($USER['impulse_motor_tech'] == 0) {
            $error = $LNG['ma_impulse_drive_required'];
        } elseif ($targetGalaxy != $PLANET['galaxy']) {
            $error = $LNG['ma_not_send_other_galaxy'];
        } elseif (empty($target)) {
            $error = $LNG['ma_planet_doesnt_exists'];
        } elseif (!$this->fleetFunctions()->inMissileRange($PLANET, $target, $USER[$resource[117]])) {
            $error = $LNG['ma_no_range'];
        } elseif (!in_array($primaryTarget, $reslist['defense']) && $primaryTarget != 0) {
            $error = $LNG['ma_wrong_target'];
        } elseif ($missileCount == 0) {
            $error = $LNG['ma_no_missiles'];
        } elseif ($anz <= 0) {
            $error = $LNG['ma_add_missile_number'];
        }

        if ($this->configs->get()->adm_attack == 1 && $target['authattack'] > $USER['authlevel']) {
            $error = $LNG['fl_admin_attack'];
        } elseif ($target['urlaubs_modus']) {
            $error = $LNG['fl_in_vacation_player'];
        }

        $sql = 'SELECT `total_points`
            FROM %%STATPOINTS%%
            WHERE `id_owner` = :userId AND `stat_type` = 1';

        try {
            $USER += $this->db->selectSingle($sql, [
                ':userId' => $USER['id'],
            ]) ?: ['total_points' => 0];
        } catch (Exception $exception) {
            $USER += ['total_points' => 0];
        }

        $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($target));

        if ($IsNoobProtec['NoobPlayer']) {
            $error = $LNG['fl_week_player'];
        } elseif ($IsNoobProtec['StrongPlayer']) {
            if ($this->configs->get()->noob_protection_outlaw) {
                $db->update('UPDATE %%USERS%% SET outlaw_time = :outlawTime WHERE id = :userId;', [
                    ':outlawTime' => $this->state->runtime->timestamp,
                    ':userId'     => $USER['id'],
                ]);
                $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
                $statsHandler->increment($USER['id'], AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
            } else {
                $error = $LNG['fl_strong_player'];
            }
        }

        if ($error != "") {
            $this->printMessage($error);
        }

        $Duration       = $this->fleetFunctions()->getMIPDuration($PLANET['system'], $targetSystem);

        $DefenseLabel   = ($primaryTarget == 0) ? $LNG['ma_all'] : $LNG['tech'][$primaryTarget];

        $fleetArray     = [INTERPLANETARY_MISSILE => $anz];

        $fleetStartTime = $this->state->runtime->timestamp + $Duration;
        $fleetStayTime  = $fleetStartTime;
        $fleetEndTime   = $fleetStartTime;

        $fleetResource  = [
            RESOURCE_METAL      => 0,
            RESOURCE_CRYSTAL    => 0,
            RESOURCE_DEUT       => 0,
        ];

        $this->fleetFunctions()->sendFleet(
            $fleetArray,
            10,
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            $PLANET['planet_type'],
            $target['id_owner'],
            $target['planetID'],
            $targetGalaxy,
            $targetSystem,
            $targetPlanet,
            $targetType,
            $fleetResource,
            $fleetStartTime,
            $fleetStayTime,
            $fleetEndTime,
            0,
            $primaryTarget,
            1, // missiles cannot be recalled
        );
        $url = 'game?page=galaxy&galaxy=' . $targetGalaxy . '&system=' . $targetSystem;
        // the amount is numeric, the texts are translations (trusted markup)
        $this->printMessage(new \Twig\Markup('<b>' . (int) $anz . '</b>' . $LNG['ma_missiles_sended'] . $DefenseLabel, 'UTF-8'), [[
            'label' => $LNG['sys_back'],
            'url'   => $url,
        ]], [
            $url,
            3,
        ]);
        throw new \App\Game\Core\EndOfRequest();
    }
}
