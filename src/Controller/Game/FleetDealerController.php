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
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;

/** Ship dealer: sells ships of the current planet for resources (modes: show, send). */
#[\App\Routing\GamePage('game.php', 'fleetDealer')]
#[\App\Routing\GamePage('game.php', 'fleetDealer', uniPrefix: true)]
class FleetDealerController extends AbstractGameController
{
    public static $requireModule = 'MODULE_FLEET_TRADER';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Sells ships (request: shipID, count) of the current planet: only ships listed in `trade_allowed_ships`, not in vacation mode, with the
     * planet row locked. Pays the ship cost minus the `trade_charge` percent in resources and removes the ships.
     */
    public function send()
    {
        $USER = $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $LNG = $this->state->LNG;
        $pricelist = $this->state->pricelist;
        $resource = $this->state->resource;
        $shipID = $this->http()->_GP('shipID', 0);
        $Count = max(0, round($this->http()->_GP('count', 0.0)));
        $allowedShipIDs = explode(',', $this->configs->get()->trade_allowed_ships);

        if ($USER['urlaubs_modus'] == 1) {
            $this->printMessage($LNG['fl_vacation_mode_active'], [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetDealer',
                ],
            ]);
        }

        if (
            empty($shipID)
            || empty($Count)
            || !in_array($shipID, $allowedShipIDs)
        ) {
            $this->printMessage($LNG['tr_exchange_error'], [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetDealer',
                ],
            ]);
        }

        $shipColumn = $resource[$shipID];
        $db = $this->db;
        $db->startTransaction();
        $sql = 'SELECT * FROM %%PLANETS%% WHERE id = :planetID FOR UPDATE;';
        $lockedPlanet = $db->selectSingle($sql, [
            ':planetID' => $PLANET['id'],
        ]);

        if (empty($lockedPlanet) || $lockedPlanet[$shipColumn] < $Count) {
            $db->rollback();
            $this->printMessage($LNG['tr_exchange_error'], [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetDealer',
                ],
            ]);
        }

        $tradeCharge = 1 - ($this->configs->get()->trade_charge / 100);
        $resources = [
            RESOURCE_METAL => $Count * $pricelist[$shipID]['cost'][RESOURCE_METAL] * $tradeCharge,
            RESOURCE_CRYSTAL => $Count * $pricelist[$shipID]['cost'][RESOURCE_CRYSTAL] * $tradeCharge,
            RESOURCE_DEUT => $Count * $pricelist[$shipID]['cost'][RESOURCE_DEUT] * $tradeCharge,
        ];

        // Debit ships first with a stock guard so a parallel sell cannot
        // credit resources twice for the same units.
        $sql = 'UPDATE %%PLANETS%% SET ' . $shipColumn . ' = ' . $shipColumn . ' - :count'
            . ' WHERE id = :planetID AND ' . $shipColumn . ' >= :count;';
        $db->update($sql, [
            ':count'    => $Count,
            ':planetID' => $PLANET['id'],
        ]);

        if ($db->rowCount() !== 1) {
            $db->rollback();
            $this->printMessage($LNG['tr_exchange_error'], [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetDealer',
                ],
            ]);
        }

        $this->ecoObj->addResources($PLANET['id'], $resources, $PLANET);
        $PLANET[$shipColumn] = $lockedPlanet[$shipColumn] - $Count;
        $db->commit();

        $this->printMessage($LNG['tr_exchange_done'], [
            [
                'label' => $LNG['sys_forward'],
                'url'   => 'game?page=fleetDealer',
            ],
        ]);
    }

    /**
     * Ship dealer form with the tradeable ships and the charge.
     */
    public function show(): Response
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $pricelist = $this->state->pricelist;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;
        $Cost = [];

        $allowedShipIDs = explode(',', $this->configs->get()->trade_allowed_ships);

        foreach ($allowedShipIDs as $shipID) {
            if (in_array($shipID, $reslist['fleet']) || in_array($shipID, $reslist['defense'])) {
                $Cost[$shipID]  = [$PLANET[$resource[$shipID]], $LNG['tech'][$shipID], $pricelist[$shipID]['cost']];
            }
        }

        if (empty($Cost)) {
            $this->printMessage($LNG['ft_empty'], [
                [
                    'label' => $LNG['sys_back'],
                    'url'   => 'game?page=fleetDealer',
                ],
            ]);
        }

        $shipImg = [];
        foreach ($allowedShipIDs as $shipID) {
            $shipImg[$shipID] = $this->state->THEME->asset('gebaeude/' . $shipID . '.gif');
        }

        $this->assign([
            'shipIDs'   => $allowedShipIDs,
            'shipImg'   => $shipImg,
            'CostInfos' => $Cost,
            'Charge'    => $this->configs->get()->trade_charge,
        ]);

        return $this->render('game/page.fleetDealer.default.html.twig');
    }
}
