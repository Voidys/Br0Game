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
use App\Game\Achievements\MiscAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\GalaxyRows;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

/** Galaxy view: planets, debris and players of one solar system. */
#[\App\Routing\GamePage('game.php', 'galaxy')]
#[\App\Routing\GamePage('game.php', 'galaxy', uniPrefix: true)]
class GalaxyController extends AbstractGameController
{
    public static $requireModule = 'MODULE_RESEARCH';

    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $resource = & $this->state->resource;
        $LNG = & $this->state->LNG;
        $reslist = & $this->state->reslist;
        $pricelist = & $this->state->pricelist;
        $config			= $this->configs->get();
        $db = $this->db;

        $galaxyLeft		= $this->http()->_GP('galaxyLeft', '');
        $galaxyRight	= $this->http()->_GP('galaxyRight', '');
        $systemLeft		= $this->http()->_GP('systemLeft', '');
        $systemRight	= $this->http()->_GP('systemRight', '');
        $galaxy			= min(max($this->http()->_GP('galaxy', (int) $PLANET['galaxy']), 1), $config->max_galaxy);
        $system			= min(max($this->http()->_GP('system', (int) $PLANET['system']), 1), $config->max_system);

        if (!empty($galaxyLeft)) {
            $galaxy	= $galaxy - 1;
            if ($galaxy < 1) {
                $galaxy = $config->max_galaxy;
            }
        } elseif (!empty($galaxyRight)) {
            $galaxy	= $galaxy + 1;
            if ($galaxy > $config->max_galaxy) {
                $galaxy = 1;
            }
        }

        if (!empty($systemLeft)) {
            $system	= $system - 1;
            if ($system < 1) {
                $system = $config->max_system;
            }
        } elseif (!empty($systemRight)) {
            $system	= $system + 1;
            if ($system > $config->max_system) {
                $system = 1;
            }
        }

        if ($galaxy != $PLANET['galaxy'] || $system != $PLANET['system']) {
            if ($PLANET['deuterium'] < $config->deuterium_cost_galaxy) {
                $this->miscAchievements()->checkGalaxyAchievements($USER['id']);
                $this->printMessage($LNG['gl_no_deuterium_to_view_galaxy'], [[
                    'label'	=> $LNG['sys_back'],
                    'url'	=> 'game?page=galaxy',
                ]]);
            } else {
                $resources = [RESOURCE_DEUT => $config->deuterium_cost_galaxy];
                $this->ecoObj->removeResources($PLANET['id'], $resources, $PLANET);
                $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
                $statsHandler->increment($USER['id'], AdvancedStatsEnum::GALAXY_SCROLL_SPENDING, $config->deuterium_cost_galaxy);
            }
        }

        $targetDefensive    = $reslist['defense'];
        $targetDefensive[]	= INTERCEPTOR_MISSILE;
        $missileSelector[0]	= $LNG['gl_all_defenses'];
        foreach ($targetDefensive as $Element) {
            $missileSelector[$Element] = $LNG['tech'][$Element];
        }

        $galaxyRows	= new GalaxyRows($this->db, $this->configs, $this->state);
        $galaxyRows->setGalaxy($galaxy);
        $galaxyRows->setSystem($system);
        $Result	= $galaxyRows->getGalaxyData();
        if (gettype($Result) == "NULL") {
            $Result = [];
        }

        $hiddenDebris = ($pricelist[SHIP_PROBE]['cost'][RESOURCE_METAL] + $pricelist[SHIP_PROBE]['cost'][RESOURCE_CRYSTAL]) * ($config->fleet_debris_percentage / 100);

        $Duration = $this->fleetFunctions()->getMIPDuration($PLANET['system'], $system);

        $colonizable = [];
        // allowPlanetPosition() depends only on the distance to the nearest edge (1, 2, 3, or further in), so
        // each tier is asked once instead of once per position
        $allowedByTier = [];
        for ($i = 1; $i <= $config->max_planets; $i++) {
            $tier = min($i - 1, $config->max_planets - $i, 3);
            $allowedByTier[$tier] ??= $this->playerUtil()->allowPlanetPosition($i, $USER);
            $allowPlanetPosition = $allowedByTier[$tier];
            if ($allowPlanetPosition && !isset($Result[$i])) {
                $colonizable[$i] = 'colorPositive';
            } else {
                $colonizable[$i] = 'colorNegative';
            }
        }

        $planetDataNew = $this->playerUtil()->getPlanetDataPlanetConfig();

        require_once 'src/Game/Support/planet_data.php';

        $enablePlanetStyles = $USER['enable_planet_styles'];

        $recyclerCount = 0;
        foreach ($reslist['ship_type'][SHIP_TYPE_RECYCLER] as $elementID) {
            $recyclerCount += $PLANET[$resource[$elementID]];
        }

        $pirateBase = null;
        if ($this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES)) {
            $sql = "SELECT `id`, `planet_type` as 'type', `der_metal` as 'metal', `der_crystal` as 'crystal' FROM %%PIRATE_BASES%% 
                WHERE `galaxy` = :galaxy AND `system` = :system AND `id_owner` = :userId";
            $pirateBase = $db->selectSingle($sql, [
                ':galaxy'   => $galaxy,
                ':system'   => $system,
                ':userId'   => $USER['id'],
            ]);

            if (!empty($pirateBase)) {
                $pirateBase['name'] = $LNG['type_planet_4'];
                $pirateBase['missions'] = [
                    20 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_ATTACK),
                    21 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_SPY),
                    22 => $this->viewHelpers()->isModuleAvailable(MODULE_MISSION_RECYCLE),
                ];
                $THEME = & $this->state->THEME;
                $pirateBase['image'] = $THEME->asset("planeten/pirate_base.jpg");
            }
        }

        $this->addScript('galaxy.js');
        $this->assign([
            'GalaxyRows'				=> $Result,
            'pirateBase'                => $pirateBase,
            'colonizable'               => $colonizable,
            'planetcount'				=> sprintf($LNG['gl_populed_planets'], count($Result)),
            'galaxy'					=> $galaxy,
            'system'					=> $system,
            'planet'					=> '',
            'type'						=> $type,
            'maxfleetcount'				=> $this->fleetFunctions()->getCurrentFleets($USER['id']),
            'fleetmax'					=> $this->fleetFunctions()->getMaxFleetSlots($USER),
            'currentmip'				=> $PLANET[$resource[INTERPLANETARY_MISSILE]],
            'recyclers'   				=> $recyclerCount,
            'spyprobes'   				=> $PLANET[$resource[SHIP_PROBE]],
            'spyShips'					=> [SHIP_PROBE => $USER['spio_anz']],
            'settings_fleetactions'		=> $USER['settings_fleetactions'],
            'current_galaxy'			=> $PLANET['galaxy'],
            'current_system'			=> $PLANET['system'],
            'current_planet'			=> $PLANET['planet'],
            'planet_type' 				=> $PLANET['planet_type'],
            'max_planets'               => $config->max_planets,
            'missileSelector'			=> $missileSelector,
            'hiddenDebris'				=> $hiddenDebris,
            'ShortStatus'				=> [
                'vacation'					=> $LNG['gl_short_vacation'],
                'banned'					=> $LNG['gl_short_ban'],
                'inactive'					=> $LNG['gl_short_inactive'],
                'longinactive'				=> $LNG['gl_short_long_inactive'],
                'noob'						=> $LNG['gl_short_newbie'],
                'strong'					=> $LNG['gl_short_strong'],
                'enemy'						=> $LNG['gl_short_enemy'],
                'friend'					=> $LNG['gl_short_friend'],
                'member'					=> $LNG['gl_short_member'],
            ],
            'duration'                  => $Duration,
            'enablePlanetStyles'        => $enablePlanetStyles,
            'planetDataNew'             => $planetDataNew,
            'servertime'                => $this->viewHelpers()->formatDate("M D d H:i:s", $this->state->runtime->timestamp, $USER['timezone']),
        ]);

        return $this->render('game/page.galaxy.default.html.twig');
    }
}
