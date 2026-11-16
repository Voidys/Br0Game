<?php

namespace App\Game\Core;

/**
 * Which missions a fleet may fly to a target (module switches, target ownership and type, ship composition) and the
 * phalanx scan rules of the galaxy view.
 *
 * Collaborators: ViewHelpers::isModuleAvailable (module switches), ConfigRegistry, Singleton (reslist, current
 * user/planet), PlayerUtil (noob protection), FleetMath (phalanx range). Read-only, no DB writes.
 */
class FleetMissionRules
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Mission selector data for the fleet page: available missions plus the stay-time options of expedition / hold /
     * trade missions (expedition hours are divided by the universe's expo_hold_multiplier).
     *
     * @param array $USER flying user
     * @param array $MisInfo target info (planet, planettype, Ship, IsAKS)
     * @param array $Planet target planet row (may be empty)
     * @return array{MissionSelector: array, StayBlock: array, Exchange: bool}
     */
    public function getFleetMissions(array $USER, array $MisInfo, array $Planet): array
    {
        $resource = $this->state->resource;
        $Missions = $this->getAvailableMissions($USER, $MisInfo, $Planet);
        $stayBlock = [];
        $exchange = false;

        $haltSpeed = $this->configs->get($USER['universe'])->expo_hold_multiplier;

        if (in_array(MISSION_EXPEDITION, $Missions)) {
            for ($i = 1; $i <= $USER[$resource[124]]; $i++) {
                $stayBlock[$i] = round($i / $haltSpeed, 2);
            }
        } elseif (in_array(MISSION_HOLD, $Missions)) {
            $stayBlock = [1 => 1, 2 => 2, 4 => 4, 8 => 8, 12 => 12, 16 => 16, 32 => 32];
        } elseif (in_array(MISSION_TRADE, $Missions)) {
            $stayBlock = [1 => 1, 2 => 2, 4 => 4, 8 => 8, 12 => 12, 24 => 24, 48 => 48, 168 => 168];
            $exchange = true;
        }

        return ['MissionSelector' => $Missions, 'StayBlock' => $stayBlock, 'Exchange' => $exchange];
    }

    private function onlyShipByID(array $Ships, int $ShipID): bool
    {
        return isset($Ships[$ShipID]) && count($Ships) === 1;
    }

    /**
     * Mission ids the fleet may fly to the target. Rules depend on the target position (expedition, trade, pirate base
     * slots beyond max_planets), debris fields (recycling), ACS invitations, free vs. used planets, own vs. foreign
     * planets, spy-probe-only fleets, destroy-capable ships on moons, and on every mission's module switch.
     *
     * @param array $USER flying user
     * @param array $MissionInfo target info (planet, planettype, Ship [shipId => count], IsAKS)
     * @param array $GetInfoPlanet target planet row (may be empty)
     * @return list<int>
     */
    public function getAvailableMissions(array $USER, array $MissionInfo, array $GetInfoPlanet): array
    {
        $view = $this->viewHelpers();
        $YourPlanet = (!empty($GetInfoPlanet['id_owner']) && $GetInfoPlanet['id_owner'] == $USER['id']) ? true : false;
        $UsedPlanet = (!empty($GetInfoPlanet['id_owner'])) ? true : false;
        $ExpeditionPosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 1)) ? true : false;
        $TradePosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 2)) ? true : false;
        $PirateBasePosition = ($MissionInfo['planet'] == ($this->configs->get($USER['universe'])->max_planets + 3)) ? true : false;
        $availableMissions = [];

        if ($ExpeditionPosition && $view->isModuleAvailable(MODULE_MISSION_EXPEDITION)) {
            $availableMissions[] = MISSION_EXPEDITION;
        } elseif ($TradePosition && $view->isModuleAvailable(MODULE_MISSION_TRADE)) {
            $availableMissions[] = MISSION_TRADE;
        } elseif ($PirateBasePosition && $view->isModuleAvailable(MODULE_PIRATE_BASES) && $MissionInfo['planettype'] != 2) {
            $availableMissions = $this->pirateBaseMissions($MissionInfo, $YourPlanet);
        } elseif ($MissionInfo['planettype'] == 2 && $view->isModuleAvailable(MODULE_MISSION_RECYCLE)) {
            $availableMissions = $this->recyclingMissions($MissionInfo, $GetInfoPlanet, $UsedPlanet, $YourPlanet, $PirateBasePosition);
        } elseif (
            !empty($MissionInfo['IsAKS']) && !$YourPlanet && $UsedPlanet
            && ($view->isModuleAvailable(MODULE_MISSION_ATTACK) || $view->isModuleAvailable(MODULE_MISSION_ACS))
        ) {
            if ($view->isModuleAvailable(MODULE_MISSION_ATTACK)) {
                $availableMissions[] = MISSION_ATTACK;
            }
            if ($view->isModuleAvailable(MODULE_MISSION_ACS)) {
                $availableMissions[] = MISSION_ACS;
            }
        } else {
            $availableMissions = $this->planetMissions($MissionInfo, $UsedPlanet, $YourPlanet);
        }

        return $availableMissions;
    }

    /**
     * Missions to a pirate base slot (not a debris field): attack on own, ACS, spy with a single probe type.
     *
     * @return list<int>
     */
    private function pirateBaseMissions(array $MissionInfo, bool $YourPlanet): array
    {
        $view = $this->viewHelpers();
        $availableMissions = [];

        if ($YourPlanet && $view->isModuleAvailable(MODULE_MISSION_ATTACK)) {
            $availableMissions[] = MISSION_PIRATEBASE_ATTACK;
        }
        if (!empty($MissionInfo['IsAKS']) && $view->isModuleAvailable(MODULE_MISSION_ACS)) {
            $availableMissions[] = MISSION_ACS;
        }
        if ($YourPlanet && $this->onlyShipByID($MissionInfo['Ship'], 210) && $view->isModuleAvailable(MODULE_MISSION_SPY)) {
            $availableMissions[] = MISSION_PIRATEBASE_SPY;
        }

        return $availableMissions;
    }

    /**
     * Recycling missions to a debris field: needs a recycler in the fleet and a used target with debris
     * (or an active wreckfield), or an own pirate base.
     *
     * @return list<int>
     */
    private function recyclingMissions(array $MissionInfo, array $GetInfoPlanet, bool $UsedPlanet, bool $YourPlanet, bool $PirateBasePosition): array
    {
        $reslist = $this->state->reslist;
        $availableMissions = [];

        $recs = false;
        foreach ($MissionInfo['Ship'] as $ShipID => $ShipCount) {
            if (isset($reslist['ship_type'][SHIP_TYPE_RECYCLER][$ShipID]) && $ShipCount > 0) {
                $recs = true;
                break;
            }
        }
        if ($recs && $UsedPlanet && !empty($GetInfoPlanet)) {
            if ($PirateBasePosition && $YourPlanet && $this->viewHelpers()->isModuleAvailable(MODULE_PIRATE_BASES)) {
                $availableMissions[] = MISSION_PIRATEBASE_RECYCLING;
            } elseif (($GetInfoPlanet['der_metal'] + $GetInfoPlanet['der_crystal']) > 0 || $GetInfoPlanet['tf_active'] == 1) {
                $availableMissions[] = MISSION_RECYCLING;
            }
        }

        return $availableMissions;
    }

    /**
     * Missions to a regular planet or moon: colonisation of free planets; transport, spy, transfer, attack, hold,
     * station and destruction (moons, with a Deathstar/Void Titan) for used ones.
     *
     * @return list<int>
     */
    private function planetMissions(array $MissionInfo, bool $UsedPlanet, bool $YourPlanet): array
    {
        $view = $this->viewHelpers();
        $availableMissions = [];

        if (!$UsedPlanet) {
            if (
                isset($MissionInfo['Ship'][208]) && $MissionInfo['planettype'] == 1
                && $view->isModuleAvailable(MODULE_MISSION_COLONY)
            ) {
                $availableMissions[] = MISSION_COLONISATION;
            }
        } else {
            if ($view->isModuleAvailable(MODULE_MISSION_TRANSPORT)) {
                $availableMissions[] = MISSION_TRANSPORT;
            }

            if (
                !$YourPlanet && $this->onlyShipByID($MissionInfo['Ship'], 210)
                && $view->isModuleAvailable(MODULE_MISSION_SPY)
            ) {
                $availableMissions[] = MISSION_SPY;
            }

            if (!$YourPlanet) {
                if ($view->isModuleAvailable(MODULE_MISSION_TRANSFER)) {
                    $availableMissions[] = MISSION_TRANSFER;
                }

                if ($view->isModuleAvailable(MODULE_MISSION_ATTACK)) {
                    $availableMissions[] = MISSION_ATTACK;
                }
                if ($view->isModuleAvailable(MODULE_MISSION_HOLD)) {
                    $availableMissions[] = MISSION_HOLD;
                }
            } elseif ($view->isModuleAvailable(MODULE_MISSION_STATION)) {
                $availableMissions[] = MISSION_STATION;
            }

            if (
                !$YourPlanet && $MissionInfo['planettype'] == 3
                && (isset($MissionInfo['Ship'][SHIP_RIP]) || isset($MissionInfo['Ship'][VOID_TITAN]))
                && $view->isModuleAvailable(MODULE_MISSION_DESTROY)
            ) {
                $availableMissions[] = MISSION_DESTRUCTION;
            }
        }

        return $availableMissions;
    }

    /**
     * Phalanx scan rules (moved from the phalanx page, used by it and the galaxy view): whether the current planet's
     * phalanx may scan $toGalaxy:$toSystem (same galaxy, phalanx built, module on, enough deuterium, not on vacation,
     * target not noob/strong protected, system within the phalanx range - wrapping in circular galaxies).
     */
    public function allowPhalanx($toGalaxy, $toSystem, $targetUser)
    {
        $PLANET = $this->state->PLANET;
        $resource = $this->state->resource;
        $USER = $this->state->USER;
        $checknoob = $this->playerUtil()->checkNoobProtectionFor($this->models()->user($USER), $this->models()->user($targetUser));

        if ($PLANET['galaxy'] != $toGalaxy || $PLANET[$resource[42]] == 0 || !$this->viewHelpers()->isModuleAvailable(MODULE_PHALANX) || $PLANET[$resource[903]] < PHALANX_DEUTERIUM || $USER['urlaubs_modus'] == 1 || $checknoob['NoobPlayer'] || $checknoob['StrongPlayer']) {
            return false;
        }

        $PhRange	= (new FleetMath($this->db, $this->configs, $this->state))->getPhalanxRange($PLANET[$resource[42]]);
        // if ($this->configs->get()->galaxy_type == 2) {
        // TODO or else
        // } else if
        if ($this->configs->get()->galaxy_type == 1) {
            $max = $this->configs->get()->max_system;
            $thisSystem = $PLANET['system'];

            if ($thisSystem + $PhRange > $max) {
                $systemMax = $thisSystem + $PhRange - $max;
            } else {
                $systemMax = $thisSystem + $PhRange;
            }

            if ($thisSystem - $PhRange < 1) {
                $systemMin = $max + ($thisSystem - $PhRange);
            } else {
                $systemMin = $thisSystem - $PhRange;
            }

            if ($PhRange * 2 >= $max) {
                return true;
            } elseif ($systemMin <= $systemMax) {
                return $toSystem >= $systemMin && $toSystem <= $systemMax;
            } else {
                return ($toSystem >= $systemMin && $toSystem <= $max) || ($toSystem >= 1 && $toSystem <= $systemMax);
            }
        } else {
            $systemMin  = max(1, $PLANET['system'] - $PhRange);
            $systemMax  = $PLANET['system'] + $PhRange;

            return $toSystem >= $systemMin && $toSystem <= $systemMax;
        }
    }
}
