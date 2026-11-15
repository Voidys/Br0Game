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
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\MarketManager;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

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

    private function checkSlots($USER)
    {
        $LNG = & $this->state->LNG;
        $ActualFleets = $this->fleetFunctions()->getCurrentFleets($USER['id']);
        if ($this->fleetFunctions()->getMaxFleetSlots($USER) <= $ActualFleets) {
            return ['result' => -1, 'message' => $LNG['fl_no_slots']];
        }
        return ['result' => 0];
    }

    private function checkVmode()
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        if ($USER['urlaubs_modus'] == 1) {
            return [
                'buyable' => false,
                'reason' => $LNG['fa_vacation_mode_current'],
            ];
        }
        return [
            'buyable' => true,
            'reason' => '',
        ];
    }
    private function checkDiplo($visibility, $level, $seller_ally, $ally, $receiverID)
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $blocked = $this->playerUtil()->get_block_status($USER['id'], $receiverID);
        $blockedInverse = $this->playerUtil()->get_block_status($receiverID, $USER['id']);
        $buddy = $this->playerUtil()->get_buddy_status($USER['id'], $receiverID);
        if (($visibility == 2 && $level == 5) || $blocked['block_trade'] || $blockedInverse['block_trade']) {
            return [
                'buyable' => false,
                'reason' => $LNG['market_buyable_no_enemies'],
            ];
        }

        if ($visibility == 1 && !$buddy && $ally != $seller_ally && ($level == null || $level > 3)) {
            return [
                'buyable' => false,
                'reason' => $LNG['market_buyable_only_trade_partners'],
            ];
        }

        return [
            'buyable' => true,
            'reason' => '',
        ];
    }

    private function getResourceTradeHistory()
    {
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $sql = 'SELECT
			UNIX_TIMESTAMP(`buy_time`) as time_unix,
			`ex_resource_type` as res_type,
			`ex_resource_amount` as amount,
			`resource_type`,
			`resource_amount`,
			seller.`resource_metal` as metal,
			seller.`resource_crystal` as crystal,
			seller.`resource_deuterium` as deuterium
			FROM %%TRADES%%
            JOIN %%LOG_FLEETS%% seller ON seller.`id` = `seller_fleet_id`
            JOIN %%LOG_FLEETS%% buyer ON buyer.`id` = `buyer_fleet_id`
			WHERE `transaction_type` = 0 AND seller.`universe` = :universe ORDER BY `time_unix` DESC LIMIT 40;';
        $trades = $db->select($sql, [
            ':universe' => $this->universe()->current(),
        ]);
        $resourceNames = [
            1 => $LNG['tech'][901],
            2 => $LNG['tech'][902],
            3 => $LNG['tech'][903],
        ];
        foreach ($trades as &$trade) {
            $this->assignTradeHistoryTime($trade);
            $offer = $this->resolveOfferedResource(
                (int) $trade['resource_type'],
                $trade['resource_amount'],
                $trade['metal'],
                $trade['crystal'],
                $trade['deuterium'],
                $resourceNames,
            );
            $costType = (int) $trade['res_type'];
            $trade['offer_type'] = $offer['id'];
            $trade['offer_amount'] = $offer['amount'];
            $trade['offer_name'] = $offer['name'];
            $trade['cost_type'] = $costType;
            $trade['cost_amount'] = $trade['amount'];
            $trade['cost_name'] = $resourceNames[$costType] ?? ' ';
        }
        unset($trade);

        return $trades;
    }

    private function getFleetTradeHistory()
    {
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $sql = 'SELECT
			seller.`ships` as fleet,
			UNIX_TIMESTAMP(`buy_time`) as time_unix,
			`ex_resource_type` as res_type,
			`ex_resource_amount` as amount
			FROM %%TRADES%%
            JOIN %%LOG_FLEETS%% seller ON seller.`id` = `seller_fleet_id`
            JOIN %%LOG_FLEETS%% buyer ON buyer.`id` = `buyer_fleet_id`
			WHERE `transaction_type` = 1 AND seller.`universe` = :universe ORDER BY `time_unix` DESC LIMIT 40;';

        $trades = $db->select($sql, [
            ':universe' => $this->universe()->current(),
        ]);
        $resourceNames = [
            1 => $LNG['tech'][901],
            2 => $LNG['tech'][902],
            3 => $LNG['tech'][903],
        ];
        for ($i = 0; $i < count($trades); $i++) {
            $this->assignTradeHistoryTime($trades[$i]);
            $fleet =  $this->fleetFunctions()->unserialize($trades[$i]['fleet']);
            $fleet_str = '';
            foreach ($fleet as $name => $amount) {
                $fleet_str .= $LNG['shortNames'][$name] . ' x' . $amount . "\n";
            }
            $costType = (int) $trades[$i]['res_type'];
            $trades[$i]['fleet_str'] = $fleet_str;
            $trades[$i]['cost_type'] = $costType;
            $trades[$i]['cost_amount'] = $trades[$i]['amount'];
            $trades[$i]['cost_name'] = $resourceNames[$costType] ?? ' ';
        }
        return $trades;
    }

    /**
     * @param array<string, mixed> $trade
     */
    private function assignTradeHistoryTime(array &$trade): void
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $timestamp = (int) ($trade['time_unix'] ?? 0);
        $trade['time_unix'] = $timestamp;
        $trade['time'] = $timestamp > 0
            ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $timestamp, $USER['timezone'])
            : '';
    }

    /**
     * @param array<int, string> $resourceNames
     * @return array{id: int, amount: mixed, name: string}
     */
    private function resolveOfferedResource($offerType, $offerAmount, $metal, $crystal, $deuterium, array $resourceNames)
    {
        if ($offerType < 1 || $offerType > 3) {
            if ($metal > 0) {
                $offerType = 1;
                $offerAmount = $metal;
            } elseif ($crystal > 0) {
                $offerType = 2;
                $offerAmount = $crystal;
            } elseif ($deuterium > 0) {
                $offerType = 3;
                $offerAmount = $deuterium;
            }
        }

        return [
            'id' => (int) $offerType,
            'amount' => $offerAmount,
            'name' => $resourceNames[$offerType] ?? ' ',
        ];
    }

    private function calculateFleetSize($fleetResult, $shipType)
    {
        $pricelist = & $this->state->pricelist;
        $PLANET = & $this->state->PLANET;
        $resource = & $this->state->resource;
        // sorry world.
        $amount = $fleetResult['ex_resource_amount'];

        $F1capacity = 0;
        $F1type = 0;
        if ($shipType == 1) {
            // PRIO for LC
            $F1capacity = $pricelist[SHIP_SMALL_CARGO]['capacity'];
            $F1type = SHIP_SMALL_CARGO;
        } else {
            // PRIO for HC
            $F1capacity = $pricelist[SHIP_LARGE_CARGO]['capacity'];
            $F1type = SHIP_LARGE_CARGO;
        }

        $F1 = min($PLANET[$resource[$F1type]], ceil($amount / $F1capacity));

        //taken
        $amountTMP = $amount - $F1 * $F1capacity;
        // If still fleet needed
        $F2 = 0;
        $F2capacity = 0;
        $F2type = 0;
        if ($amountTMP > 0) {
            if ($shipType == 1) {
                // We need HC
                $F2capacity = $pricelist[SHIP_LARGE_CARGO]['capacity'];
                $F2type = SHIP_LARGE_CARGO;
            } else {
                // We need LC
                $F2capacity = $pricelist[SHIP_SMALL_CARGO]['capacity'];
                $F2type = SHIP_SMALL_CARGO;
            }
            $F2 = min($PLANET[$resource[$F2type]], ceil($amountTMP / $F2capacity));
            $amountTMP -= $F2 * $F2capacity;
        }

        if ($amountTMP > 0) {
            return false;
        }

        return [$F1type => $F1, $F2type => $F2];
    }

    private function doBuy()
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $resource = & $this->state->resource;
        $LNG = & $this->state->LNG;
        $FleetID = $this->http()->_GP('fleetID', 0);
        $shipType = $this->http()->_GP('shipType', "");
        $db = $this->db;

        //Vmode check
        $vmode = $this->checkVmode();
        if (!$vmode['buyable']) {
            return $vmode['reason'];
        }

        //Slots checking
        $checkResult = $this->checkSlots($USER);
        if ($checkResult['result'] < 0) {
            return $checkResult['message'];
        }
        //Get trade fleet
        $sql = "SELECT f.*, t.*, u.`ally_id` FROM %%FLEETS%% f
            JOIN %%TRADES%% t ON f.`id` = t.`seller_fleet_id`
            JOIN %%USERS%% u ON f.`owner` = u.`id`
            WHERE f.`id` = :id AND f.`status` = :fleetState AND f.`universe` = :universe FOR UPDATE;";
        $fleetResult = $db->selectSingle($sql, [
            ':id' => $FleetID,
            ':fleetState' => FLEET_HOLD,
            ':universe' => $this->universe()->current(),
        ]);

        //Error: no results
        if ($db->rowCount() == 0) {
            return $LNG['market_p_msg_not_found'];
        }

        // an offer cannot be bought by its seller
        if ($fleetResult['owner'] == $USER['id']) {
            return $LNG['market_p_msg_own_offer'];
        }

        // a crafted offer may carry an invalid wanted resource
        if ($fleetResult['ex_resource_type'] >= 4 || $fleetResult['ex_resource_type'] <= 0) {
            return $LNG['market_p_msg_wrong_resource_type'];
        }

        $isFleetTrade = ($fleetResult['transaction_type'] == 1);

        if ($fleetResult['filter_visibility'] != 0 && $USER['id'] != $fleetResult['owner']) {
            //Check packts
            $sql = "SELECT * FROM %%DIPLO%% WHERE ((owner_1 = :ow AND owner_2 = :ow2) OR"
                . " (owner_2 = :ow AND owner_1 = :ow2)) AND accept = 1;";
            $res = $db->select($sql, [
                ':ow' => $USER['ally_id'],
                ':ow2' => $fleetResult['ally_id'],
            ]);
            $level = null;
            if ($db->rowCount() != 0) {
                $level = $res[0]['level'];
            }
            $buy = $this->checkDiplo(
                $fleetResult['filter_visibility'],
                $level,
                $fleetResult['ally_id'],
                $USER['ally_id'],
                $fleetResult['owner'],
            );
            if (!$buy['buyable']) {
                return $buy['reason'];
            }
        }

        $isPush = $this->checkPush($fleetResult, $USER['id'], $fleetResult['owner']);
        if ($isPush) {
            return $LNG['market_buyable_no_tech'];
        }

        //if not in range 1-3
        if ($fleetResult['resource_amount'] > $fleetResult['ex_resource_amount'] || $isFleetTrade) {
            $amount['ex_resource_amount'] = $fleetResult['resource_amount'];
        } else {
            $amount['ex_resource_amount'] = $fleetResult['ex_resource_amount'];
        }
        $fleetNeeded = $this->calculateFleetSize($amount, $shipType);
        if (
            $fleetResult['ex_resource_type'] >= 4
            || $fleetResult['ex_resource_type'] <= 0
        ) {
            return $LNG['market_p_msg_wrong_resource_type'];
        }

        // not enough ships
        if (!$fleetNeeded) {
            return $LNG['market_p_msg_more_ships_is_needed'];
        }

        $fleetArray = array_filter($fleetNeeded);
        $amount = $fleetResult['ex_resource_amount'];
        $marketplace = ($this->configs->get($USER['universe'])->max_planets + 2);

        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        if ($isFleetTrade) {
            $Distance = $this->fleetFunctions()->getTargetDistance(
                [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
                [$fleetResult['end_galaxy'], $fleetResult['end_system'], $fleetResult['end_planet']],
            );
        } else {
            $Distance = $this->fleetFunctions()->getTargetDistance(
                [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
                [$PLANET['galaxy'], $PLANET['system'], $marketplace],
            );
        }

        $SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $USER);
        $Duration = $this->fleetFunctions()->getMissionDuration(10, $SpeedAllMin, $Distance, $SpeedFactor, $USER);
        $consumption = $this->fleetFunctions()->getFleetConsumption($fleetArray, $Duration, $Distance, $USER, $SpeedFactor);

        if ($isFleetTrade) {
            $fleet = $this->fleetFunctions()->unserialize($fleetResult['ships']);
            $fleetSpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($fleet, $USER);
            $fleetDuration = $this->fleetFunctions()->getMissionDuration(10, $fleetSpeedAllMin, $Distance, $SpeedFactor, $USER);
            $bonusConsumption = $this->fleetFunctions()->getFleetConsumption($fleet, $fleetDuration, $Distance, $USER, $SpeedFactor);
            $consumption += $bonusConsumption;
        }

        $fleetStartTime = $Duration + $this->state->runtime->timestamp;
        $fleetStayTime = $fleetStartTime;
        $fleetEndTime = $fleetStayTime + $Duration;

        $met = 0;
        $cry = 0;
        $deu = 0;
        if ($fleetResult['ex_resource_type'] == 1) {
            $met = $amount;
        } elseif ($fleetResult['ex_resource_type'] == 2) {
            $cry = $amount;
        } elseif ($fleetResult['ex_resource_type'] == 3) {
            $deu = $amount;
        }

        $fleetResource = [
            RESOURCE_METAL => $met,
            RESOURCE_CRYSTAL => $cry,
            RESOURCE_DEUT => $deu,
        ];

        if (
            $PLANET[$resource[RESOURCE_METAL]] - $fleetResource[RESOURCE_METAL] < 0
            || $PLANET[$resource[RESOURCE_CRYSTAL]] - $fleetResource[RESOURCE_CRYSTAL] < 0
            || $PLANET[$resource[RESOURCE_DEUT]] - $fleetResource[RESOURCE_DEUT] - $consumption < 0
        ) {
            return $LNG['market_p_msg_resources_error'];
        }

        $PLANET[$resource[RESOURCE_METAL]] -= $fleetResource[RESOURCE_METAL];
        $PLANET[$resource[RESOURCE_CRYSTAL]] -= $fleetResource[RESOURCE_CRYSTAL];
        $PLANET[$resource[RESOURCE_DEUT]] -= $fleetResource[RESOURCE_DEUT] + $consumption;
        $buyerMission = $isFleetTrade ? MISSION_TRANSPORT : MISSION_TRADE;
        $targetGalaxy = $isFleetTrade ? $fleetResult['start_galaxy'] : $PLANET['galaxy'];
        $targetSystem = $isFleetTrade ? $fleetResult['start_system'] : $PLANET['system'];
        $targetPlanet = $isFleetTrade ? $fleetResult['start_planet'] : $marketplace;
        $targetType = $isFleetTrade ? $fleetResult['start_type'] : 1;
        $buyerfleet = $this->fleetFunctions()->sendFleet(
            $fleetArray,
            $buyerMission /*buyerfleet to automatically get rerouted*/,
            $USER['id'],
            $PLANET['id'],
            $PLANET['galaxy'],
            $PLANET['system'],
            $PLANET['planet'],
            $PLANET['planet_type'],
            $fleetResult['owner'],
            $fleetResult['start_id'],
            $targetGalaxy,
            $targetSystem,
            $targetPlanet,
            $targetType,
            $fleetResource,
            $fleetStartTime,
            $fleetStayTime,
            $fleetEndTime,
            0,
            0,
            1,
            $consumption,
        );



        /////////////////////////////////////////////////////////////////////////////
        /// SEND/
        $sql = "SELECT * FROM %%USERS%% as u 
            INNER JOIN %%USERS_SETTINGS%% as s ON s.`user_id` = u.`id` 
            WHERE u.`id` = :userId;";
        $USER_2 = $this->db->selectSingle($sql, [':userId' => $fleetResult['owner']]);
        $fleetArray = $this->fleetFunctions()->unserialize($fleetResult['ships']);
        $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $Distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $marketplace],
            [$fleetResult['end_galaxy'], $fleetResult['end_system'], $marketplace],
        );
        $SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($fleetArray, $USER_2);
        $Duration = $this->fleetFunctions()->getMissionDuration(10, $SpeedAllMin, $Distance, $SpeedFactor, $USER_2);
        if ($PLANET['galaxy'] == $fleetResult['end_galaxy'] && $PLANET['system'] == $fleetResult['end_system'] && !$isFleetTrade) {
            $Distance = $this->fleetFunctions()->getTargetDistance(
                [$fleetResult['start_galaxy'], $fleetResult['start_system'], $marketplace],
                [$fleetResult['end_galaxy'], $fleetResult['end_system'], $marketplace],
            );
            $Duration = $this->fleetFunctions()->getMissionDuration(10, $SpeedAllMin, $Distance, $SpeedFactor, $USER_2);
            $startTime = $fleetResult['start_time'];
            $fleetStartTime = $fleetResult['arrival_time'];
            $fleetStayTime = $fleetStartTime + TIME_6_HOURS;
            $fleetEndTime = $fleetStayTime + $Duration;
        } elseif (!$isFleetTrade) {
            $startTime = $this->state->runtime->timestamp;
            $fleetStartTime = $Duration + $this->state->runtime->timestamp;
            $fleetStayTime = $fleetStartTime + TIME_6_HOURS;
            $fleetEndTime = $fleetStayTime + $Duration;
        } else {
            $startTime = $fleetResult['start_time'];
            $fleetStartTime = $Duration + $this->state->runtime->timestamp;
            $fleetStayTime = $fleetStartTime;
            $fleetEndTime = $fleetStayTime + $Duration;
        }
        $params = [
            ':fleetID' => $FleetID,
            ':target_owner' => $USER['id'],
            ':end_id' => $isFleetTrade ? $PLANET['id'] : 0,
            ':end_planet' => $isFleetTrade ? $PLANET['planet'] : $marketplace,
            ':end_system' => $PLANET['system'],
            ':end_galaxy' => $PLANET['galaxy'],
            ':start_time' => $startTime,
            ':arrival_time' => $fleetStartTime,
            ':stay_time' => $fleetStayTime,
            ':return_time' => $fleetEndTime,
            ':mission' => $fleetResult['transaction_type'] == 0 ? MISSION_TRADE : MISSION_TRANSFER,
            ':cannotCancel' => 1,
            ':fleetState' => FLEET_OUTWARD,
        ];
        $logParams = array_merge($params, [
            ':start_time_formated' => $this->db->formatDate($startTime),
            ':arrival_time_formated' => $this->db->formatDate($fleetStartTime),
            ':stay_time_formated' => $this->db->formatDate($fleetStayTime),
            ':return_time_formated' => $this->db->formatDate($fleetEndTime),
        ]);
        $params = array_merge($params, [
            ':metal' => 0,
            ':crystal' => 0,
            ':deuterium' => 0,
        ]);
        $sql = "UPDATE %%FLEETS%% SET
            `mission` = :mission,
            `target_owner` = :target_owner,
            `start_time` = :start_time,
            `arrival_time` = :arrival_time,
            `stay_time` = :stay_time,
            `return_time` = :return_time,
            `end_id` = :end_id,
            `end_planet` = :end_planet,
            `end_system` = :end_system,
            `end_galaxy` = :end_galaxy,
            `resource_metal` = :metal,
            `resource_crystal` = :crystal,
            `resource_deuterium` = :deuterium,
            `status` = :fleetState,
            `cannotCancel` = :cannotCancel
            WHERE `id` = :fleetID;";
        $db->update($sql, $params);
        $sql = "UPDATE %%LOG_FLEETS%% SET 
            `mission` = :mission,
            `target_owner` = :target_owner,
            `start_time` = :start_time,
            `start_time_formated` = :start_time_formated,
            `arrival_time` = :arrival_time,
            `arrival_time_formated` = :arrival_time_formated,
            `stay_time` = :stay_time,
            `stay_time_formated` = :stay_time_formated,
            `return_time` = :return_time,
            `return_time_formated` = :return_time_formated,
            `end_id` = :end_id,
            `end_planet` = :end_planet,
            `end_system` = :end_system,
            `end_galaxy` = :end_galaxy,
            `status` = :fleetState,
            `cannotCancel` = :cannotCancel
            WHERE `id` = :fleetID;";
        $db->update($sql, $logParams);
        $sql = 'UPDATE %%FLEETS_EVENT%% SET `time` = :endTime WHERE `fleetID` = :fleetId;';
        $db->update($sql, [
            ':fleetId'  => $FleetID,
            ':endTime'  => $fleetStartTime,
        ]);

        $sql = 'UPDATE %%TRADES%% SET `buyer_fleet_id` = :buyerFleetId,`buy_time` = NOW()
            WHERE `seller_fleet_id` = :fleetId;';
        $db->update($sql, [
            ':fleetId'  => $FleetID,
            ':buyerFleetId' => $buyerfleet,
        ]);

        $LC = 0;
        $HC = 0;
        if (array_key_exists(202, $fleetArray)) {
            $LC = $fleetArray[202];
        }
        if (array_key_exists(203, $fleetArray)) {
            $HC = $fleetArray[203];
        }
        $sql = "SELECT `marketplace_galaxy`, `marketplace_system` FROM %%TRADES%% 
            WHERE `seller_fleet_id` = :fleetId;";
        $target = $db->selectSingle($sql, [
            ':fleetId' => $FleetID,
        ]);
        // To customer
        $Message = sprintf(
            $LNG['market_msg_trade_bought'],
            sprintf(
                '%s:%s:%s',
                $isFleetTrade ? $fleetResult['start_galaxy'] : $target['marketplace_galaxy'],
                $isFleetTrade ? $fleetResult['start_system'] : $target['marketplace_system'],
                $isFleetTrade ? $fleetResult['start_planet'] : $marketplace,
            ),
            $fleetResource[RESOURCE_METAL],
            $LNG['tech'][RESOURCE_METAL],
            $fleetResource[RESOURCE_CRYSTAL],
            $LNG['tech'][RESOURCE_CRYSTAL],
            $fleetResource[RESOURCE_DEUT],
            $LNG['tech'][RESOURCE_DEUT],
            $consumption,
            $LNG['tech'][RESOURCE_DEUT],
        );
        $this->playerUtil()->sendMessage(
            $USER['id'],
            0,
            $LNG['market_msg_trade_from'],
            MESSAGE_TYPE_SYSTEM,
            $LNG['market_msg_trade_topic'],
            $Message,
            $this->state->runtime->timestamp,
            1,
            $fleetResult['universe'],
        );

        // To salesmen
        $LNG_SALESMEN = $this->playerUtil()->getLanguage($USER_2['lang']);
        $Message = sprintf(
            $LNG_SALESMEN['market_msg_trade_sold'],
            $PLANET['galaxy'] . ":" . $PLANET['system'] . ":" . ($isFleetTrade ? $marketplace : $PLANET['planet']),
            $fleetResult['resource_metal'],
            $LNG_SALESMEN['tech'][RESOURCE_METAL],
            $fleetResult['resource_crystal'],
            $LNG_SALESMEN['tech'][RESOURCE_CRYSTAL],
            $fleetResult['resource_deuterium'],
            $LNG_SALESMEN['tech'][RESOURCE_DEUT],
        );
        $this->playerUtil()->sendMessage(
            $fleetResult['owner'],
            0,
            $LNG_SALESMEN['market_msg_trade_from'],
            MESSAGE_TYPE_SYSTEM,
            $LNG_SALESMEN['market_msg_trade_topic'],
            $Message,
            $this->state->runtime->timestamp,
            1,
            $fleetResult['universe'],
        );
        return sprintf($LNG['market_p_msg_sent'], $LC, $HC);
    }

    /** automatic push prevention **/
    private function checkPush($fleetsRow, $buyer, $seller, ?MarketManager $pMarket = null)
    {
        $pMarket = $pMarket ?? new MarketManager($this->db, $this->configs, $this->state);
        $offer = 0;
        $fleetMarket = $fleetsRow['transaction_type'] == 1;
        // an offer with an invalid wanted resource is never buyable (and must not break the market page)
        $askType = (int) $fleetsRow['ex_resource_type'];
        if ($askType < 1 || $askType > 3) {
            return true;
        }
        $ask = $pMarket->convertExpResTypeToDeut($askType, $fleetsRow['ex_resource_amount']);

        if ($fleetMarket) {
            $fleet = $this->fleetFunctions()->unserialize($fleetsRow['ships']);
            return $pMarket->isFleetPush($fleet, $ask, $seller, $buyer);
        } else {
            $offerArray = [
                'metal' => $fleetsRow['resource_metal'],
                'crystal' => $fleetsRow['resource_crystal'],
                'deuterium' => $fleetsRow['resource_deuterium'],
            ];

            $offer = $pMarket->convertToDeut($offerArray);
            return $pMarket->isPush($offer, $ask, $seller, $buyer);
        }
    }

    public function show(): Response
    {
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        $LNG = & $this->state->LNG;

        $GetAction = $this->http()->_GP('action', "");

        $message = "";
        $db = $this->db;

        if ($GetAction == "buy") {
            // doBuy() reduces $PLANET in memory; restore it when the purchase is rolled back
            $planetBackup = $PLANET;
            // Lock the offer row for the whole purchase, so a concurrent buyer
            // waits and then no longer finds it with fleet_mess = 2.
            $db->startTransaction();
            try {
                $message = $this->doBuy();
                $db->commit();
            } catch (\Throwable $e) {
                $db->rollback();
                $PLANET = $planetBackup;
                // Deadlock/contention on FOR UPDATE must not hit the generic
                // "dev was notified" error page (see PR review on #1774).
                $message = $LNG['market_p_msg_purchase_failed'];
            }
        }

        $sql = 'SELECT u.*, t.*, packts.*, f.*
			FROM %%FLEETS%% f
			JOIN %%USERS%% u ON f.`owner` = u.`id`
            JOIN %%TRADES%% t ON f.`id` = t.`seller_fleet_id`
			LEFT JOIN (
				SELECT `owner_2` as al ,`level`, `accept` FROM %%DIPLO%% WHERE `owner_1` = :al
				UNION
				SELECT `owner_1` as al,`level`, `accept` FROM %%DIPLO%% WHERE `owner_2` = :al) as packts
			ON al = u.`ally_id`
			WHERE f.`mission` = :trade
			AND f.`universe` = :universe
            AND f.`status` = :fleetState
            AND t.`buyer_fleet_id` IS NULL
            ORDER BY f.`stay_time` ASC;';
        $fleetResult = $db->select($sql, [
            ':al'           => $USER['ally_id'],
            ':trade'        => MISSION_TRADE,
            ':buyerid'      => $USER['id'],
            ':universe'     => $this->universe()->current(),
            ':fleetState'   => FLEET_HOLD,
        ]);

        $FlyingFleetList = [];
        $pMarket = new MarketManager($this->db, $this->configs, $this->state);

        foreach ($fleetResult as $fleetsRow) {
            $resourceNames = [
                1 => $LNG['tech'][RESOURCE_METAL],
                2 => $LNG['tech'][RESOURCE_CRYSTAL],
                3 => $LNG['tech'][RESOURCE_DEUT],
            ];
            $resourceN = $resourceNames[$fleetsRow['ex_resource_type']] ?? ' ';
            $offer = $this->resolveOfferedResource(
                (int) $fleetsRow['resource_type'],
                $fleetsRow['resource_amount'],
                $fleetsRow['resource_metal'],
                $fleetsRow['resource_crystal'],
                $fleetsRow['resource_deuterium'],
                $resourceNames,
            );
            $offerType = $offer['id'];
            $offerAmount = $offer['amount'];
            $offerName = $offer['name'];

            //Level of diplo
            if ($fleetsRow['accept'] == 0) {
                $fleetsRow['level'] = null;
            }
            $marketplace = ($this->configs->get($USER['universe'])->max_planets + 2);
            $SpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
            //FROM
            $FROM_fleet =  $this->fleetFunctions()->unserialize($fleetsRow['ships']);
            $FROM_Distance = $this->fleetFunctions()->getTargetDistance(
                [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
                [$fleetsRow['end_galaxy'], $fleetsRow['end_system'], $marketplace],
            );
            $FROM_SpeedAllMin = $this->fleetFunctions()->getFleetMaxSpeed($FROM_fleet, $fleetsRow);
            $FROM_Duration = $this->fleetFunctions()->getMissionDuration(
                10,
                $FROM_SpeedAllMin,
                $FROM_Distance,
                $SpeedFactor,
                $fleetsRow,
            );
            $sql = "SELECT `marketplace_galaxy`, `marketplace_system` 
                FROM %%TRADES%% WHERE `seller_fleet_id` = :fleetId;";
            $target = $db->selectSingle($sql, [
                ':fleetId' => $fleetsRow['id'],
            ]);
            //TO
            $TO_Distance = $this->fleetFunctions()->getTargetDistance(
                [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
                [$target['marketplace_galaxy'], $target['marketplace_system'], $marketplace],
            );
            $TO_LC_SPEED = $this->fleetFunctions()->getFleetMaxSpeed([202 => 1], $USER);
            $TO_LC_DUR = $this->fleetFunctions()->getMissionDuration(10, $TO_LC_SPEED, $TO_Distance, $SpeedFactor, $USER);
            $TO_HC_SPEED = $this->fleetFunctions()->getFleetMaxSpeed([203 => 1], $USER);
            $TO_HC_DUR = $this->fleetFunctions()->getMissionDuration(10, $TO_HC_SPEED, $TO_Distance, $SpeedFactor, $USER);

            $fleet_str = '';
            foreach ($FROM_fleet as $name => $amount) {
                $fleet_str .= $LNG['shortNames'][$name] . ' x' . $amount . "\n";
            }

            $buyer = $USER['id'];
            $seller = $fleetsRow['owner'];

            if ($buyer == $seller) {
                $buy = [
                    'buyable' => false,
                    'reason' => 'N/A', // todo $LNG['text'] - creative input needed
                ];
            } elseif ($this->checkPush($fleetsRow, $buyer, $seller, $pMarket)) {
                $buy = [
                    'buyable' => false,
                    'reason' => $LNG['market_buyable_no_tech'],
                ];
            } else {
                //Level 5 - enemies
                //Level 0 - 3 alliance
                $buy = $this->checkDiplo(
                    $fleetsRow['filter_visibility'],
                    $fleetsRow['level'],
                    $fleetsRow['ally_id'],
                    $USER['ally_id'],
                    $seller,
                );
            }

            $fleetValue = $pMarket->getFleetValue($FROM_fleet);
            $askType = (int) $fleetsRow['ex_resource_type'];
            $ask = ($askType >= 1 && $askType <= 3)
                ? $pMarket->convertExpResTypeToDeut($askType, $fleetsRow['ex_resource_amount'])
                : 0;
            if ($fleetsRow['transaction_type'] == 1) {
                $tradeDeut = $fleetValue;
            } elseif ($offerType >= 1 && $offerType <= 3) {
                $tradeDeut = $pMarket->convertExpResTypeToDeut($offerType, (float) $offerAmount);
            } else {
                $tradeDeut = 0;
            }
            $tradeRatio = $ask > 0 ? $tradeDeut / $ask : 0;
            $fleetRatio = $this->viewHelpers()->prettyNumber($tradeRatio, MarketManager::REFERENCE_DECIMALS);

            $FlyingFleetList[] = [
                'id'                        => $fleetsRow['id'],
                'type'                      => $fleetsRow['transaction_type'],
                'resource_metal'            => $fleetsRow['resource_metal'],
                'resource_crystal'          => $fleetsRow['resource_crystal'],
                'resource_deuterium'        => $fleetsRow['resource_deuterium'],
                'fleet'                     => $fleet_str,
                'diplo'                     => $fleetsRow['level'],
                'from_alliance'             => $fleetsRow['ally_id'] == $USER['ally_id'],
                'possible_to_buy'           => $buy['buyable'],
                'reason'                    => $buy['reason'],
                'offer_resource'            => $offerName,
                'offer_resource_id'         => $offerType,
                'offer_resource_amount'     => $offerAmount,
                'wanted_resource'           => $resourceN,
                'wanted_resource_id'        => $fleetsRow['ex_resource_type'],
                'wanted_resource_amount'    => $fleetsRow['ex_resource_amount'],
                'end'                       => $fleetsRow['stay_time'] - $this->state->runtime->timestamp,
                'from_duration'             => $FROM_Duration,
                'to_lc_duration'            => $TO_LC_DUR,
                'to_hc_duration'            => $TO_HC_DUR,
                'offer_deut'                => $tradeDeut,
                'ask_deut'                  => $ask,
                'fleet_ratio'               => $fleetRatio,
            ];
        }

        $deutRelative = $pMarket->getDeutRelativeRatios();
        $refrates = $deutRelative;
        $ratioBaseline = 'deuterium';
        // $refrates = $pMarket->getReferenceRatios();
        // $ratioBaseline = array_search(min($deutRelative), $deutRelative, true);

        $this->assign([
            'message'               => $message,
            'FlyingFleetList'       => $FlyingFleetList,
            'resourceHistory'       => $this->getResourceTradeHistory(),
            'fleetHistory'          => $this->getFleetTradeHistory(),
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
