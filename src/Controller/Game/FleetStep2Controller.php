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
use App\Game\Core\FleetFunctions;
use App\Game\Core\HTTP;
use App\Game\Core\MarketManager;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/** Fleet dispatch, step 2: mission, cargo and trade options (modes: show, checkResources). */
#[\App\Routing\GamePage('game.php', 'fleetStep2')]
#[\App\Routing\GamePage('game.php', 'fleetStep2', uniPrefix: true)]
class FleetStep2Controller extends AbstractGameController
{
    public static $requireModule = 'MODULE_FLEET_TABLE';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Fleet dispatch step 2 (mission choice). Request: token (fleet stored by step 1; unknown tokens go back to the fleet page), galaxy/system/planet/type
     * (target), speed (percent, validated against the allowed speeds), target_mission (-1 = pick), group (public id of an alliance combat group the
     * player is invited to). Loads the target (planet, moon, or the player's own pirate base; empty debris fields are refused), asks FleetFunctions
     * which missions are possible (none: message), chooses the mission when none was given (the best of the player's mission priorities, or
     * transport for own/befriended targets, spy for a lone probe, otherwise attack; attack on a combat group becomes ACS), computes distance, duration
     * and deuterium consumption (refused when the planet lacks the deuterium), and stores speed, distance and target in the session fleet entry.
     * Builds the confirmation questions (outlaw, diplomatic partner, buddy, colony warning), the stay times and the trade market rates for trade
     * missions, refuses missing ships and expeditions with probes only, and renders the form.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $this->addScript('flotten.js');

        $targetGalaxy = $this->http()->_GP('galaxy', 0);
        $targetSystem = $this->http()->_GP('system', 0);
        $targetPlanet = $this->http()->_GP('planet', 0);
        $targetType = $this->http()->_GP('type', 0);
        $targetMission = $this->http()->_GP('target_mission', -1);
        $fleetSpeed = $this->http()->_GP('speed', 0);
        $fleetGroupPublicId = $this->http()->_GP('group', '');
        $token = $this->http()->_GP('token', '');

        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (!isset($fleet[$token])) {
            $this->fleetFunctions()->gotoFleetPage();
        }

        $fleetArray = $fleet[$token]['fleet'];

        [$targetPlanetData, $fleetGroup] = $this->loadTarget($targetGalaxy, $targetSystem, $targetPlanet, $targetType, $fleetGroupPublicId);

        $MissionOutput = $this->possibleMissions($targetPlanetData, $fleetArray, $fleetGroup, $targetGalaxy, $targetSystem, $targetPlanet, $targetType);

        if ($targetMission == -1) {
            $targetMission = $this->preferredMission($MissionOutput['MissionSelector'], $targetMission);
        }
        // validate the speed first: the flight duration divides by it
        if (!$this->fleetFunctions()->checkUserSpeed((int) $fleetSpeed)) {
            $this->fleetFunctions()->gotoFleetPage(0);
        }

        [$MaxFleetSpeed, $distance, $duration, $consumption] = $this->flightPlan($fleetArray, $targetGalaxy, $targetSystem, $targetPlanet, $fleetSpeed);

        if ($consumption > $PLANET['deuterium']) {
            $this->backToFleetTable($LNG['fl_not_enough_deuterium']);
        }

        $fleet[$token] = array_merge($fleet[$token], [
            'speed' => $MaxFleetSpeed,
            'distance' => $distance,
            'targetGalaxy' => $targetGalaxy,
            'targetSystem' => $targetSystem,
            'targetPlanet' => $targetPlanet,
            'targetType' => $targetType,
            'fleetGroup' => $fleetGroup,
            'fleetSpeed' => $fleetSpeed,
            'ownPlanet' => $PLANET['id'],
        ]);
        $session->fleet = $fleet;
        $session->save();

        if ($targetMission == 0) {
            $targetMission = $this->guessMission($MissionOutput['MissionSelector'], $targetPlanetData, $fleetArray);
        }

        if (!empty($fleetGroup) && $targetMission == MISSION_ATTACK) {
            $targetMission  = MISSION_ACS;
        }

        $questionList = $this->confirmationQuestions($targetPlanetData, $targetGalaxy, $targetSystem);

        $fleetData  = [
            'fleetroom'     => floatToString($fleet[$token]['fleetRoom']),
            'consumption'   => floatToString($consumption),
        ];

        $tradeMarket = empty($MissionOutput['Exchange']) ? null : $this->tradeMarketData($fleetArray);

        $this->assign($this->formVariables($questionList, $fleetData, $consumption, $targetMission, $MissionOutput, $tradeMarket, $token, $duration, $fleet[$token]['predefinedResources']));

        $this->checkShipsAvailable($fleetArray, $targetMission);

        return $this->render('game/page.fleetStep2.default.html.twig');
    }

    /**
     * Asks FleetFunctions which missions are possible against the target. Ends the request with a message when the target
     * is an empty debris field or when no mission is possible.
     *
     * @param array<string, mixed> $targetPlanetData target row from loadTarget() (empty = nothing there)
     * @param array<int, int|float> $fleetArray ship id => count
     * @return array<string, mixed> MissionSelector, StayBlock, Exchange
     */
    private function possibleMissions(array $targetPlanetData, array $fleetArray, int $fleetGroup, mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $targetType): array
    {
        $LNG = $this->state->LNG;
        if ($targetType == 2 && !empty($targetPlanetData) && ($targetPlanetData['der_metal'] + $targetPlanetData['der_crystal']) == 0
            && $targetPlanetData['tf_active'] == 0) {
            $this->backToFleetTable($LNG['fl_error_empty_derbis']);
        }

        $MissionOutput = $this->fleetFunctions()->getFleetMissions($this->state->USER, [
            'galaxy' => $targetGalaxy,
            'system' => $targetSystem,
            'planet' => $targetPlanet,
            'planettype' => $targetType,
            'IsAKS' => $fleetGroup,
            'Ship' => $fleetArray,
        ], $targetPlanetData);

        if (empty($MissionOutput['MissionSelector'])) {
            $this->backToFleetTable($LNG['fl_empty_target']);
        }

        return $MissionOutput;
    }

    /**
     * Flight data for the chosen target: the slowest ship's speed, the distance from the current planet, the flight duration
     * at the chosen speed percentage and the deuterium consumption.
     *
     * @param array<int, int|float> $fleetArray ship id => count
     * @return array{0: mixed, 1: mixed, 2: mixed, 3: mixed} max fleet speed, distance, duration, consumption
     */
    private function flightPlan(array $fleetArray, mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $fleetSpeed): array
    {
        $PLANET = $this->state->PLANET;
        $GameSpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $userModel = $this->models()->user($this->state->USER);
        $MaxFleetSpeed = $this->fleetFunctions()->fleetMaxSpeedFor($fleetArray, $userModel);
        $distance = $this->fleetFunctions()->getTargetDistance(
            [$PLANET['galaxy'], $PLANET['system'], $PLANET['planet']],
            [$targetGalaxy, $targetSystem, $targetPlanet],
        );
        $duration = $this->fleetFunctions()->missionDurationFor($fleetSpeed, $MaxFleetSpeed, $distance, $GameSpeedFactor, $userModel);
        $consumption = $this->fleetFunctions()->fleetConsumptionFor($fleetArray, $duration, $distance, $userModel, $GameSpeedFactor);

        return [$MaxFleetSpeed, $distance, $duration, $consumption];
    }

    /**
     * Template variables of the mission form: questions, fleet data, the chosen mission and the selectors from
     * FleetFunctions::getFleetMissions(), the trade market and the origin planet coordinates.
     *
     * @param array<int, string> $questionList mission => confirmation question
     * @param array<string, mixed> $fleetData fleetroom and consumption
     * @param array<string, mixed> $MissionOutput result of getFleetMissions()
     * @param array<string, mixed>|null $tradeMarket from tradeMarketData()
     * @return array<string, mixed>
     */
    private function formVariables(array $questionList, array $fleetData, mixed $consumption, mixed $targetMission, array $MissionOutput, ?array $tradeMarket, mixed $token, mixed $duration, mixed $predefinedResources): array
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;

        return [
            'questionList'      => $questionList,
            'fleetdata'         => $fleetData,
            'consumption'       => floatToString($consumption),
            'mission'           => $targetMission,
            'galaxy'            => $PLANET['galaxy'],
            'system'            => $PLANET['system'],
            'planet'            => $PLANET['planet'],
            'type'              => $PLANET['planet_type'],
            'MissionSelector'   => $MissionOutput['MissionSelector'],
            'StaySelector'      => $MissionOutput['StayBlock'],
            'Exchange'          => $MissionOutput['Exchange'],
            'tradeMarket'       => $tradeMarket,
            'fl_continue'       => $LNG['fl_continue'],
            'token'             => $token,
            'duration'          => $duration,
            'predefinedRes'     => $predefinedResources,
        ];
    }

    /**
     * Ends the request with $message and a back link to the fleet page.
     */
    private function backToFleetTable(mixed $message): void
    {
        $this->printMessage($message, [[
            'label' => $this->state->LNG['sys_back'],
            'url'   => 'game?page=fleetTable',
        ]]);
    }

    /**
     * Loads the target of the dispatch: the player's own pirate base (when $targetPlanet is the pirate slot) or the
     * planet/moon at the coordinates (debris fields hang off the planet row). For normal targets it also resolves the
     * public id of an alliance combat group the player is invited to.
     *
     * @return array{0: array<string, mixed>, 1: int} target row (empty = nothing there) and the internal combat group id (0 = none)
     */
    private function loadTarget(mixed $targetGalaxy, mixed $targetSystem, mixed $targetPlanet, mixed $targetType, mixed $fleetGroupPublicId): array
    {
        $db = $this->db;
        $USER = $this->state->USER;
        $config = $this->configs->get();
        $fleetGroup = 0;

        if ($targetPlanet == $config->max_planets + 3) {
            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, 0 as `tf_active`
                FROM %%PIRATE_BASES%% 
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND `system` = :targetSystem AND `id_owner` = :userId;";
            $targetPlanetData = $db->selectSingle($sql, [
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':userId' => $USER['id'],
            ]);
        } else {
            if (!empty($fleetGroupPublicId)) {
                $sql = "SELECT acs.`id`
                    FROM %%USERS_TO_ACS%% usersToAcs
                    INNER JOIN %%AKS%% acs ON usersToAcs.acsID = acs.`id`
                    WHERE usersToAcs.`userID` = :userID AND acs.`public_id` = :publicId;";
                $fleetGroup = (int) $db->selectSingle($sql, [
                    ':userID' => $USER['id'],
                    ':publicId' => $fleetGroupPublicId,
                ], 'id');
            }

            $sql = "SELECT `id`, `id_owner`, `der_metal`, `der_crystal`, `tf_active`
                FROM %%PLANETS%%
                WHERE `universe` = :universe AND `galaxy` = :targetGalaxy AND
                `system` = :targetSystem AND `planet` = :targetPlanet AND `planet_type` = :planetType;";
            // A moon target needs the moon row; debris fields hang off the planet (type 1)
            $targetPlanetData = $db->selectSingle($sql, [
                ':planetType' => $targetType == 3 ? 3 : 1,
                ':universe' => $this->universe()->current(),
                ':targetGalaxy' => $targetGalaxy,
                ':targetSystem' => $targetSystem,
                ':targetPlanet' => $targetPlanet,
            ]);
        }
        if (empty($targetPlanetData)) {
            $targetPlanetData = [];
        }

        return [$targetPlanetData, $fleetGroup];
    }

    /**
     * Picks the mission the player prefers (USERS_SETTINGS prioMission*, lower score wins) among the possible
     * missions. Keeps $current when none of the possible missions has a priority.
     *
     * @param list<int> $missionSelector possible missions
     */
    private function preferredMission(array $missionSelector, mixed $current): mixed
    {
        $sql = "SELECT `prioMission1` as '1', `prioMission2` as '2', `prioMission3` as '3', `prioMission4` as '4', `prioMission5` as '5',
                `prioMission6` as '6', `prioMission7` as '7', `prioMission8` as '8', `prioMission9` as '9', `prioMission17` as '17'
            FROM %%USERS_SETTINGS%%
            WHERE `user_id` = :userID ;";
        $missionprios = $this->db->selectSingle($sql, [
            ':userID'   => $this->state->USER['id'],
        ]);
        $targetMission = $current;
        $bestmission = 999;
        foreach ($missionSelector as $mission) {
            if (array_key_exists($mission, $missionprios)) {
                $score = $missionprios[$mission];
                if ($score < $bestmission) {
                    $targetMission = $mission;
                    $bestmission = $score;
                }
            }
        }

        return $targetMission;
    }

    /**
     * Allied-alliance row (diplomacy level 1, 2, 4 or 6, accepted) between the player's alliance and the alliance of
     * $targetPlayerId, or a falsy value when there is none.
     *
     * @return array<string, mixed>|false|null
     */
    private function alliedAllianceOf(int $targetPlayerId): array|false|null
    {
        $sql = "SELECT a.`id`, a.`ally_tag`, a.`ally_name`, d.`level`, d.`owner_2`
            FROM %%USERS%% u
            JOIN %%ALLIANCE%% a ON a.`id` = u.`ally_id`
            JOIN %%DIPLO%% d ON ((d.`owner_1` = u.`ally_id` AND d.`owner_2` = :ally_id)
                OR (d.`owner_2` = u.`ally_id` AND d.`owner_1` = :ally_id))
                AND d.`accept` = 1
            WHERE u.`universe` = :universe AND u.`id` = :targetPlayerId AND d.`level` IN (1,2,4,6);";

        return $this->db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
            ':targetPlayerId' => $targetPlayerId,
            ':ally_id' => $this->state->USER['ally_id'],
        ]);
    }

    /**
     * Row with the number (`anz`) of buddy relations between the player and $targetPlayerId.
     *
     * @return array<string, mixed>
     */
    private function buddyCount(int $targetPlayerId): array
    {
        $sql = "SELECT count(*) as anz FROM %%BUDDY%%
            WHERE (`sender` = :id AND `owner` = :targetPlayerId)
               OR (`sender` = :targetPlayerId AND `owner` = :id);";

        return $this->db->selectSingle($sql, [
            ':id' => $this->state->USER['id'],
            ':targetPlayerId' => $targetPlayerId,
        ]);
    }

    /**
     * Chooses the mission when the form asked for "auto" (0): the only possible one; transport on an own planet; spy for
     * a lone probe; transport for targets of allied alliances or buddies; otherwise attack.
     *
     * @param list<int> $missionSelector possible missions
     * @param array<string, mixed> $targetPlanetData target row, empty when nothing is there
     * @param array<int, int> $fleetArray ship id => count
     */
    private function guessMission(array $missionSelector, array $targetPlanetData, array $fleetArray): int
    {
        if (count($missionSelector) == 1) {
            return $missionSelector[0];
        }
        if (!empty($targetPlanetData) && $targetPlanetData['id_owner'] == $this->state->USER['id']) {
            return MISSION_TRANSPORT;
        }
        if (count($fleetArray) == 1 && isset($fleetArray[SHIP_PROBE])) {
            return MISSION_SPY;
        }
        if (empty($targetPlanetData)) {
            return MISSION_ATTACK;
        }

        $targetAllianceData = $this->alliedAllianceOf($targetPlanetData['id_owner']);
        $buddy = $this->buddyCount($targetPlanetData['id_owner']);

        return ($targetAllianceData || $buddy['anz'] > 0) ? MISSION_TRANSPORT : MISSION_ATTACK;
    }

    /**
     * Confirmation questions the form asks before certain missions: attacks (and ACS / destruction) on outlaws, allied
     * alliances or buddies; hold and spy on outlaws; colonising a system where the player already has two colonies.
     *
     * @param array<string, mixed> $targetPlanetData target row, empty when nothing is there
     * @return array<int, string> mission => question text
     */
    private function confirmationQuestions(array $targetPlanetData, mixed $targetGalaxy, mixed $targetSystem): array
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $questionList = [];
        $question = '';

        if (!empty($targetPlanetData) && $targetPlanetData['id_owner'] != $USER['id']) {
            $targetAllianceData = $this->alliedAllianceOf($targetPlanetData['id_owner']);
            $buddy = $this->buddyCount($targetPlanetData['id_owner']);
            $config = $this->configs->get();

            if ($config->noob_protection_outlaw) {

                $targetPlayerData = $this->playerUtil()->getPlayerById($targetPlanetData['id_owner']);
                $IsNoobProtec = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($targetPlayerData));

                if ($IsNoobProtec['StrongPlayer']) {
                    $question = $LNG->format('fl_attack_confirm_outlaw',
                        $targetPlayerData['username'],
                    );

                    $questionHold = $LNG->format('fl_hold_confirm_outlaw',
                        $targetPlayerData['username'],
                    );

                    $questionSpy = $LNG->format('fl_spy_confirm_outlaw',
                        $targetPlayerData['username'],
                    );
                    $questionList[MISSION_HOLD] = $questionHold;
                    $questionList[MISSION_SPY] = $questionSpy;
                }
            } elseif ($targetAllianceData) {
                $question = $LNG->format('fl_attack_confirm_diplo',
                    (($targetAllianceData['owner_2'] == $targetAllianceData['id'] && $targetAllianceData['level'] == 1) ? $LNG['al_diplo_level'][0] : $LNG['al_diplo_level'][$targetAllianceData['level']]),
                    '[' . $targetAllianceData['ally_tag'] . '] ' . $targetAllianceData['ally_name'],
                );
            } elseif ($buddy['anz'] > 0) {
                $question = $LNG['fl_attack_confirm_buddy'];
            }

            $questionList[MISSION_ATTACK] = $question;
            $questionList[MISSION_ACS] = $question;
            $questionList[MISSION_DESTRUCTION] = $question;

        }

        if (empty($targetPlanetData) && $USER['colonyWarn'] == 1) {
            $sql = "SELECT count(*) as anz 
                FROM %%PLANETS%% 
                WHERE `id_owner` = :id 
                  AND `planet_type` = '1' 
                  AND `galaxy` = :galaxy 
                  AND `system` = :system
                  AND `destruyed` = 0;";

            $coloniesInSys = $this->db->selectSingle($sql, [
                ':id' => $USER['id'],
                ':galaxy' => $targetGalaxy,
                ':system' => $targetSystem,
            ]);
            if ($coloniesInSys['anz'] >= 2) {
                $questionList[MISSION_COLONISATION] = $LNG['fl_colonize_confirm'];
            }
        }

        return $questionList;
    }

    /**
     * Trade market data for trade missions: the deuterium-relative rates of the three resources, the deuterium value
     * of the fleet's ships and the reference decimals.
     *
     * @param array<int, int> $fleetArray ship id => count
     * @return array<string, mixed>
     */
    private function tradeMarketData(array $fleetArray): array
    {
        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $deutRelative = $pMarket->getDeutRelativeRatios();

        return [
            'rates' => [
                'metal' => sprintf('%.12F', $deutRelative['metal']),
                'crystal' => sprintf('%.12F', $deutRelative['crystal']),
                'deuterium' => sprintf('%.12F', $deutRelative['deuterium']),
            ],
            'fleetDeut' => $pMarket->getFleetValue($fleetArray),
            'decimals' => MarketManager::REFERENCE_DECIMALS,
        ];
    }

    /**
     * Last sanity checks before the form is rendered; ends the request with a message when the planet no longer has
     * the ships, or when an expedition would be sent with probes only.
     *
     * @param array<int, int> $fleetArray ship id => count
     */
    private function checkShipsAvailable(array $fleetArray, mixed $targetMission): void
    {
        $resource = $this->state->resource;
        $LNG = $this->state->LNG;
        $containsOnlyProbes = true;
        foreach ($fleetArray as $Ship => $Count) {
            if ($Count > $this->state->PLANET[$resource[$Ship]]) {
                $this->backToFleetTable($LNG['fl_not_all_ship_avalible']);
            }
            if ($Ship != SHIP_PROBE) {
                $containsOnlyProbes = false;
            }
        }
        if ($targetMission == MISSION_EXPEDITION && $containsOnlyProbes) {
            $this->backToFleetTable($LNG['fl_no_probe_expos']);
        }
    }

    /**
     * XHR validation of the cargo of step 2 (request: token, mission, metal, crystal, deuterium, and for trade missions resEx = wanted resource type 1..3,
     * exchange = wanted amount, markettype 0 = resources / 1 = ships). Answers JSON: `OK` or the error text for missing resources on the planet, deuterium
     * for the flight on top of the cargo, too little cargo space, or invalid trade setups (nothing or several resources loaded, wanting the loaded
     * resource). An unknown token gives {message, error: true}.
     */
    public function checkResources(): void
    {
        $PLANET = $this->state->PLANET;
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $targetMission = $this->http()->_GP('mission', MISSION_TRANSPORT);
        $TransportMetal = max(0, round($this->http()->_GP('metal', 0.0)));
        $TransportCrystal = max(0, round($this->http()->_GP('crystal', 0.0)));
        $TransportDeuterium = max(0, round($this->http()->_GP('deuterium', 0.0)));
        $WantedResourceType = $this->http()->_GP('resEx', 0);
        $WantedResourceAmount = max(0, round($this->http()->_GP('exchange', 0.0)));
        $markettype = $this->http()->_GP('markettype', 0); //0 = resources, 1 = ships

        if ($PLANET['metal'] < $TransportMetal) {
            $this->sendJSON($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_METAL]));
        }
        if ($PLANET['crystal'] < $TransportCrystal) {
            $this->sendJSON($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_CRYSTAL]));
        }

        $token = $this->http()->_GP('token', '');
        $session = $this->sessions()->load();
        $fleet = $session->fleet;
        if (!isset($fleet[$token]) || !isset($fleet[$token]['fleet']) || !isset($fleet[$token]['distance'])
            || !isset($fleet[$token]['fleetSpeed']) || !isset($fleet[$token]['fleetRoom'])) {
            $this->sendJSON(['message' => $LNG['fl_error'], 'error' => true]);
        }
        $formData = $fleet[$token];
        $fleetArray = $formData['fleet'];
        $distance = $formData['distance'];
        $fleetSpeed = $formData['fleetSpeed'];
        $fleetStorage = $formData['fleetRoom'];

        $GameSpeedFactor = $this->fleetFunctions()->getGameSpeedFactor();
        $userModel = $this->models()->user($USER);
        $MaxFleetSpeed = $this->fleetFunctions()->fleetMaxSpeedFor($fleetArray, $userModel);
        $duration = $this->fleetFunctions()->missionDurationFor($fleetSpeed, $MaxFleetSpeed, $distance, $GameSpeedFactor, $userModel);
        $consumption = $this->fleetFunctions()->fleetConsumptionFor($fleetArray, $duration, $distance, $userModel, $GameSpeedFactor);

        if ($PLANET['deuterium'] < $TransportDeuterium + $consumption) {
            $this->sendJSON($LNG->format('fl_not_enough', $LNG['tech'][RESOURCE_DEUT]));
        }

        if ($fleetStorage < $TransportMetal + $TransportCrystal + $TransportDeuterium + $consumption || $fleetStorage < $WantedResourceAmount) {
            $this->sendJSON($LNG['fl_not_enough_space']);
        }

        if ($targetMission == MISSION_TRADE) {
            $anz = 0;
            $anz += $TransportMetal > 0 ? 1 : 0;
            $anz += $TransportCrystal > 0 ? 1 : 0;
            $anz += $TransportDeuterium > 0 ? 1 : 0;

            if ($anz == 0 && $markettype == 0) {
                $this->sendJSON($LNG['fl_trade_no_resource_loaded']);
            } elseif ($anz > 1) {
                $this->sendJSON($LNG['fl_trade_to_many_resources_loaded']);
            }

            if ($TransportMetal > 0 && $WantedResourceType == 1) {
                $this->sendJSON($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_METAL], $LNG['tech'][RESOURCE_METAL]));
            } elseif ($TransportCrystal > 0 && $WantedResourceType == 2) {
                $this->sendJSON($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_CRYSTAL], $LNG['tech'][RESOURCE_CRYSTAL]));
            } elseif ($TransportDeuterium > 0 && $WantedResourceType == 3) {
                $this->sendJSON($LNG->format('fl_trade_same_resource', $LNG['tech'][RESOURCE_DEUT], $LNG['tech'][RESOURCE_DEUT]));
            }
        }

        $this->sendJSON('OK');
    }
}
