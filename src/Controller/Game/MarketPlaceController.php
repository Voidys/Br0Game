<?php

/**
 *  Steemnova
 *   by Adam "dotevo" Jordanek 2018
 *
 * For the full copyright and license information, please view the LICENSE
 *
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\MarketManager;
use App\Game\Market\MarketOfferListing;
use App\Game\Market\MarketPurchase;
use App\Game\Market\MarketRules;
use App\Game\Market\MarketTradeHistory;

/** Market place: player trade offers for resources and fleets. */
#[\App\Routing\GamePage('game.php', 'marketPlace')]
#[\App\Routing\GamePage('game.php', 'marketPlace', uniPrefix: true)]
class MarketPlaceController extends AbstractGameController
{
    public static $requireModule = 'MODULE_MISSION_TRADE';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Marketplace page: buys an offer when `action=buy` (inside a transaction), then lists the open offers,
     * the reference ratios and the trade histories.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;

        $GetAction = $this->http()->_GP('action', "");

        $message = "";
        $db = $this->db;
        $rules = new MarketRules($this->db, $this->configs, $this->state);

        if ($GetAction == "buy") {
            // MarketPurchase::buy() reduces $PLANET in memory; restore it when the purchase is rolled back
            $planetBackup = $this->state->PLANET;
            // Lock the offer row for the whole purchase, so a concurrent buyer
            // waits and then no longer finds it with fleet_mess = 2.
            $db->startTransaction();
            try {
                $message = (new MarketPurchase($this->db, $this->configs, $this->state, $rules))->buy();
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                $this->state->PLANET = $planetBackup;
                // Deadlock/contention on FOR UPDATE must not hit the generic
                // "dev was notified" error page (see PR review on #1774).
                $message = $LNG['market_p_msg_purchase_failed'];
            }
        }

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $FlyingFleetList = (new MarketOfferListing($this->db, $this->configs, $this->state, $rules))->offers($pMarket);
        $history = new MarketTradeHistory($this->db, $this->configs, $this->state, $rules);

        $deutRelative = $pMarket->getDeutRelativeRatios();
        $refrates = $deutRelative;
        $ratioBaseline = 'deuterium';
        // $refrates = $pMarket->getReferenceRatios();
        // $ratioBaseline = array_search(min($deutRelative), $deutRelative, true);

        $this->assign([
            'message'               => $message,
            'FlyingFleetList'       => $FlyingFleetList,
            'resourceHistory'       => $history->resourceTrades(),
            'fleetHistory'          => $history->fleetTrades(),
            'ratio_metal'           => $this->viewHelpers()->prettyNumber($refrates['metal'], MarketManager::REFERENCE_DECIMALS),
            'ratio_crystal'         => $this->viewHelpers()->prettyNumber($refrates['crystal'], MarketManager::REFERENCE_DECIMALS),
            'ratio_deuterium'       => $this->viewHelpers()->prettyNumber($refrates['deuterium'], MarketManager::REFERENCE_DECIMALS),
            'ratio_base_metal'      => sprintf('%.12F', $deutRelative['metal']),
            'ratio_base_crystal'    => sprintf('%.12F', $deutRelative['crystal']),
            'ratio_base_deuterium'  => sprintf('%.12F', $deutRelative['deuterium']),
            'ratio_baseline'        => $ratioBaseline,
        ]);
        $this->addScript('marketplace.js');
        return $this->render('game/page.marketPlace.default.html.twig');
    }
}
