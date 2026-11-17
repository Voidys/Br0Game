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

namespace App\Game\Core;

/**
 * Facade over the fleet services, kept so that the many existing call sites keep working. Every method delegates:
 *
 * - FleetMath: distance, speed, duration, consumption, cargo room, slots, ranges
 * - FleetString: the "id,count;id,count" fleet string format
 * - FleetMissionRules: available missions, phalanx rules
 * - BashRules: bash protection
 * - FleetLauncher: send / recall flights, flight counts
 * - AcsService: ACS group timing
 * - FleetStatistics: point-based fleet caps, total fleet state
 */
class FleetFunctions
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public static $allowedSpeed = [
        10 => 100,
        9 => 90,
        8 => 80,
        7 => 70,
        6 => 60,
        5 => 50,
        4 => 40,
        3 => 30,
        2 => 20,
        1 => 10,
    ];

    private function math(): FleetMath
    {
        return $this->gameUtility(FleetMath::class);
    }

    private function fleetString(): FleetString
    {
        return $this->gameUtility(FleetString::class);
    }

    private function missionRules(): FleetMissionRules
    {
        return $this->gameUtility(FleetMissionRules::class);
    }

    private function bashRules(): BashRules
    {
        return $this->gameUtility(BashRules::class);
    }

    private function launcher(): FleetLauncher
    {
        return $this->gameUtility(FleetLauncher::class);
    }

    private function acs(): AcsService
    {
        return $this->gameUtility(AcsService::class);
    }

    private function statistics(): FleetStatistics
    {
        return $this->gameUtility(FleetStatistics::class);
    }

    /**
     * @see FleetMath::getExpeditionLimit()
     * @deprecated use the typed expeditionLimitFor() variant
     */
    public function getExpeditionLimit(array $USER): int
    {
        return $this->math()->getExpeditionLimit($USER);
    }

    /** @see FleetMath::expeditionLimitFor() */
    public function expeditionLimitFor(\App\Game\Model\User $user): int
    {
        return $this->math()->expeditionLimitFor($user);
    }

    /** @see FleetMath::inMissileRange() */
    public function inMissileRange(array $planet, array $target, int $levelIonEngine): bool
    {
        return $this->math()->inMissileRange($planet, $target, $levelIonEngine);
    }

    /** @see FleetMath::checkUserSpeed() */
    public function checkUserSpeed(int $speed): bool
    {
        return $this->math()->checkUserSpeed($speed);
    }

    /** @see FleetMath::getTargetDistance() */
    public function getTargetDistance(array $start, array $target): int
    {
        return $this->math()->getTargetDistance($start, $target);
    }

    /**
     * @see FleetMath::getMissionDuration()
     * @deprecated use the typed missionDurationFor() variant
     */
    public function getMissionDuration(int $SpeedFactor, int $MaxFleetSpeed, int $Distance, int $GameSpeed, array $USER): int
    {
        return $this->math()->getMissionDuration($SpeedFactor, $MaxFleetSpeed, $Distance, $GameSpeed, $USER);
    }

    /** @see FleetMath::missionDurationFor() */
    public function missionDurationFor(int $SpeedFactor, int $MaxFleetSpeed, int $Distance, int $GameSpeed, \App\Game\Model\User $user): int
    {
        return $this->math()->missionDurationFor($SpeedFactor, $MaxFleetSpeed, $Distance, $GameSpeed, $user);
    }

    /** @see FleetMath::getMIPDuration() */
    public function getMIPDuration(int $startSystem, int $targetSystem): int
    {
        return $this->math()->getMIPDuration($startSystem, $targetSystem);
    }

    /** @see FleetMath::getGameSpeedFactor() */
    public function getGameSpeedFactor(): int
    {
        return $this->math()->getGameSpeedFactor();
    }

    /**
     * @see FleetMath::getMaxFleetSlots()
     * @deprecated use the typed maxFleetSlotsFor() variant
     */
    public function getMaxFleetSlots(array $USER): int
    {
        return $this->math()->getMaxFleetSlots($USER);
    }

    /** @see FleetMath::maxFleetSlotsFor() */
    public function maxFleetSlotsFor(\App\Game\Model\User $user): int
    {
        return $this->math()->maxFleetSlotsFor($user);
    }

    /** @see FleetMath::getFleetRoom() */
    public function getFleetRoom(array $Fleet): int
    {
        return $this->math()->getFleetRoom($Fleet);
    }

    /**
     * @see FleetMath::getFleetMaxSpeed()
     * @deprecated use the typed fleetMaxSpeedFor() variant
     */
    public function getFleetMaxSpeed(array|int $Fleets, array $Player): int
    {
        return $this->math()->getFleetMaxSpeed($Fleets, $Player);
    }

    /**
     * @param array<int, mixed>|int $Fleets
     * @see FleetMath::fleetMaxSpeedFor()
     */
    public function fleetMaxSpeedFor(array|int $Fleets, \App\Game\Model\User $Player): int
    {
        return $this->math()->fleetMaxSpeedFor($Fleets, $Player);
    }

    /**
     * @see FleetMath::getFleetConsumption()
     * @deprecated use the typed fleetConsumptionFor() variant
     */
    public function getFleetConsumption(array $FleetArray, int $MissionDuration, int $MissionDistance, array $Player, int $GameSpeed): float
    {
        return $this->math()->getFleetConsumption($FleetArray, $MissionDuration, $MissionDistance, $Player, $GameSpeed);
    }

    /** @see FleetMath::fleetConsumptionFor() */
    public function fleetConsumptionFor(array $FleetArray, int $MissionDuration, int $MissionDistance, \App\Game\Model\User $Player, int $GameSpeed): float
    {
        return $this->math()->fleetConsumptionFor($FleetArray, $MissionDuration, $MissionDistance, $Player, $GameSpeed);
    }

    /** @see FleetMath::getFleetShipInfo() */
    public function getFleetShipInfo(array $FleetArray, array $Player): array
    {
        return $this->math()->getFleetShipInfo($FleetArray, $Player);
    }

    /** @see FleetMath::getPhalanxRange() */
    public function getPhalanxRange($PhalanxLevel)
    {
        return $this->math()->getPhalanxRange($PhalanxLevel);
    }

    /** @see FleetMissionRules::getFleetMissions() */
    public function getFleetMissions(array $USER, array $MisInfo, array $Planet): array
    {
        return $this->missionRules()->getFleetMissions($USER, $MisInfo, $Planet);
    }

    /** @see FleetMissionRules::getAvailableMissions() */
    public function getAvailableMissions(array $USER, array $MissionInfo, array $GetInfoPlanet): array
    {
        return $this->missionRules()->getAvailableMissions($USER, $MissionInfo, $GetInfoPlanet);
    }

    /** @see FleetMissionRules::allowPhalanx() */
    public function allowPhalanx($toGalaxy, $toSystem, $targetUser)
    {
        return $this->missionRules()->allowPhalanx($toGalaxy, $toSystem, $targetUser);
    }

    /** @see FleetString::unserialize() */
    public function unserialize(string $fleetAmount): array
    {
        return $this->fleetString()->unserialize($fleetAmount);
    }

    /** @see FleetString::serialize() */
    public function serialize(array $fleet): string
    {
        return $this->fleetString()->serialize($fleet);
    }

    /** @see AcsService::getACSDuration() */
    public function getACSDuration(int $acsId): int
    {
        return $this->acs()->getACSDuration($acsId);
    }

    /** @see AcsService::setACSTime() */
    public function setACSTime(int $timeDifference, int $acsId): bool
    {
        return $this->acs()->setACSTime($timeDifference, $acsId);
    }

    /** @see FleetLauncher::getCurrentFleets() */
    public function getCurrentFleets(int $userId, int $fleetMission = 10, bool $thisMission = false): int
    {
        return $this->launcher()->getCurrentFleets($userId, $fleetMission, $thisMission);
    }

    /** @see FleetLauncher::sendFleetBack() */
    public function sendFleetBack(array $USER, int $FleetID): bool
    {
        return $this->launcher()->sendFleetBack($USER, $FleetID);
    }

    /** @see FleetLauncher::sendFleet() */
    public function sendFleet(
        array $fleetArray,
        int $fleetMission,
        int $fleetStartOwner,
        int $fleetStartPlanetID,
        int $fleetStartPlanetGalaxy,
        int $fleetStartPlanetSystem,
        int $fleetStartPlanetPlanet,
        int $fleetStartPlanetType,
        int $fleetTargetOwner,
        int $fleetTargetPlanetID,
        int $fleetTargetPlanetGalaxy,
        int $fleetTargetPlanetSystem,
        int $fleetTargetPlanetPlanet,
        int $fleetTargetPlanetType,
        array $fleetResource,
        int $arrivalTime,
        int $stayTime,
        int $endTime,
        int $fleetGroup = 0,
        int $missileTarget = 0,
        int $cannotCancel = 0,
        float $consumption = 0.0,
    ): int {
        return $this->launcher()->sendFleet(
            $fleetArray,
            $fleetMission,
            $fleetStartOwner,
            $fleetStartPlanetID,
            $fleetStartPlanetGalaxy,
            $fleetStartPlanetSystem,
            $fleetStartPlanetPlanet,
            $fleetStartPlanetType,
            $fleetTargetOwner,
            $fleetTargetPlanetID,
            $fleetTargetPlanetGalaxy,
            $fleetTargetPlanetSystem,
            $fleetTargetPlanetPlanet,
            $fleetTargetPlanetType,
            $fleetResource,
            $arrivalTime,
            $stayTime,
            $endTime,
            $fleetGroup,
            $missileTarget,
            $cannotCancel,
            $consumption,
        );
    }

    /** @see BashRules::checkBash() */
    public function checkBash(int $Target, int $fleetGroup = 0): bool
    {
        return $this->bashRules()->checkBash($Target, $fleetGroup);
    }

    /** @see FleetStatistics::calculateMaxFactor() */
    public function calculateMaxFactor(int $universeId): int
    {
        return $this->statistics()->calculateMaxFactor($universeId);
    }

    /** @see FleetStatistics::calculateMaxFactorRes() */
    public function calculateMaxFactorRes(int $universeId): int
    {
        return $this->statistics()->calculateMaxFactorRes($universeId);
    }

    /** @see FleetStatistics::calculateCurrentTotalFleetState() */
    public function calculateCurrentTotalFleetState(int $userID): array
    {
        return $this->statistics()->calculateCurrentTotalFleetState($userID);
    }

    /**
     * Aborts a failed fleet dispatch: with the debug setting it prints file, line and the error text
     * (`fl_send_error[$Code]`) and ends the request; otherwise redirects to the fleet page. Always ends the request.
     * @param int $Code index into the language array `fl_send_error` (what went wrong).
     */
    public function gotoFleetPage($Code = 0): void
    {
        $LNG = $this->state->LNG;
        if ($this->configs->get()->debug == 1) {
            $temp = debug_backtrace();
            echo sprintf(
                "%s on %d | Code: %d | Error: %s",
                str_replace($this->state->requestServer["DOCUMENT_ROOT"], '.', $temp[0]['file']),
                $temp[0]['line'],
                $Code,
                (isset($LNG['fl_send_error'][$Code]) ? $LNG['fl_send_error'][$Code] : ''),
            );
            throw new \App\Game\Core\EndOfRequest();
        }

        $this->http()->redirectTo('game?page=fleetTable&code=' . $Code);
    }
}
