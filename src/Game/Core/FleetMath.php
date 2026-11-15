<?php

namespace App\Game\Core;

/**
 * Pure fleet mathematics: distance between coordinates, ship speed per drive tech, fleet speed (slowest ship), flight
 * duration, fuel consumption, cargo room, fleet slots, expedition limit, missile/phalanx ranges, missile flight time.
 *
 * Collaborators: ConfigRegistry (universe geometry and speed factors), Singleton (pricelist/resource tables),
 * PlayerUtil (honor point fuel boost). No DB writes. The formulas are mirrored in the client JS
 * (scripts/game/flotten.js) and pinned by FleetFunctionsTest goldens.
 */
class FleetMath
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Fuel consumption per distance unit of one ship type for the player's drive technologies, reduced by
     * 2 % per fuel efficiency level. The drive that applies is the highest one whose tech requirement is met.
     */
    private function getShipConsumption(int $Ship, array $Player): float
    {
        $pricelist = & $this->state->pricelist;

        if (($pricelist[$Ship]['tech3'] > 1 && $Player['hyperspace_motor_tech'] >= $pricelist[$Ship]['tech3'])
            || $pricelist[$Ship]['tech3'] == 1) {
            $consumption = $pricelist[$Ship]['consumption3'];
        } elseif (($pricelist[$Ship]['tech2'] > 1 && $Player['impulse_motor_tech'] >= $pricelist[$Ship]['tech2'])
            || $pricelist[$Ship]['tech2'] == 1) {
            $consumption = $pricelist[$Ship]['consumption2'];
        } else {
            $consumption = $pricelist[$Ship]['consumption1'];
        }

        $consumption *= (1 - (0.02 * $Player['fuel_efficiency_tech']));

        return $consumption;
    }

    /**
     * Speed of one ship type: base speed of the best available drive, multiplied by that drive tech (hyperspace +30 %,
     * impulse +20 %, combustion +10 % per level).
     */
    private function getShipSpeed(int $Ship, array $Player): int
    {
        $pricelist = & $this->state->pricelist;

        if (($pricelist[$Ship]['tech3'] > 1 && $Player['hyperspace_motor_tech'] >= $pricelist[$Ship]['tech3'])
            || $pricelist[$Ship]['tech3'] == 1) {
            $base_speed = $pricelist[$Ship]['speed3'];
            $speed = $base_speed * (1 + (0.3 * $Player['hyperspace_motor_tech']));
        } elseif (($pricelist[$Ship]['tech2'] > 1 && $Player['impulse_motor_tech'] >= $pricelist[$Ship]['tech2'])
            || $pricelist[$Ship]['tech2'] == 1) {
            $base_speed = $pricelist[$Ship]['speed2'];
            $speed = $base_speed * (1 + (0.2 * $Player['impulse_motor_tech']));
        } else {
            $base_speed = $pricelist[$Ship]['speed1'];
            $speed = $base_speed * (1 + (0.1 * $Player['combustion_tech']));
        }

        return $speed;
    }

    public function getExpeditionLimit(array $USER): int
    {
        $resource = & $this->state->resource;

        return floor(sqrt($USER[$resource[124]]));
    }

    /**
     * Whether the target planet is inside the missile range of the source planet (ion engine level based; wraps
     * around in circular galaxies, galaxy_type 1).
     */
    public function inMissileRange(array $planet, array $target, int $levelIonEngine): bool
    {
        if ($target['galaxy'] != $planet['galaxy']) {
            return false;
        }
        $config = $this->configs->get();
        $Range = max(floor((($levelIonEngine * 5) - 1) * $config->missile_range_factor), 0);

        if ($config->galaxy_type == 2) {
            return $Range > 1;
        } elseif ($config->galaxy_type == 1) {
            $max = $config->max_system;
            $thisSystem = $planet['system'];
            $toSystem = $target['system'];

            if ($thisSystem + $Range > $max) {
                $systemMax = $thisSystem + $Range - $max;
            } else {
                $systemMax = $thisSystem + $Range;
            }

            if ($thisSystem - $Range < 1) {
                $systemMin = $max + ($thisSystem - $Range);
            } else {
                $systemMin = $thisSystem - $Range;
            }

            if ($Range * 2 >= $max) {
                return true;
            } elseif ($systemMin <= $systemMax) {
                return $toSystem >= $systemMin && $toSystem <= $systemMax;
            } else {
                return ($toSystem >= $systemMin && $toSystem <= $max) || ($toSystem >= 1 && $toSystem <= $systemMax);
            }
        } else {
            $systemMin = $planet['system'] - $Range;
            $systemMax = $planet['system'] + $Range;

            return $target['system'] >= $systemMin && $target['system'] <= $systemMax;
        }
    }

    public function checkUserSpeed(int $speed): bool
    {
        return isset(FleetFunctions::$allowedSpeed[$speed]);
    }

    /**
     * Calculates the distance to the target
     *
     * Changes needs to be done in scripts/game/flotten.js#GetDistance() aswell
     *
     * @return int
     */
    public function getTargetDistance(array $start, array $target): int
    {
        $thisGalaxy = $start[0];
        $thisSystem = $start[1];
        $thisPlanet = $start[2];
        $targetGalaxy = $target[0];
        $targetSystem = $target[1];
        $targetPlanet = $target[2];
        $config = $this->configs->get();

        if ($thisGalaxy != $targetGalaxy) {
            if ($config->uni_type == 2) {
                return $config->galaxy_distance_factor;
            } elseif ($config->uni_type == 1) {
                $max = $config->max_galaxy;
                $dist = 0;

                if (abs($thisGalaxy - $targetGalaxy) < ceil($max / 2)) {
                    $dist = abs($thisGalaxy - $targetGalaxy);
                } else {
                    $dist = floor($max / 2) - (abs($thisGalaxy - $targetGalaxy) - ceil($max / 2));
                }
                return $dist * $config->galaxy_distance_factor;
            } else {
                return abs($thisGalaxy - $targetGalaxy) * $config->galaxy_distance_factor;
            }
        } elseif ($thisSystem != $targetSystem) {
            if ($config->galaxy_type == 2) {
                return $config->system_distance_factor + $config->system_distance;
            } elseif ($config->galaxy_type == 1) {
                $max = $config->max_system;
                $dist = 0;

                if (abs($thisSystem - $targetSystem) < ceil($max / 2)) {
                    $dist = abs($thisSystem - $targetSystem);
                } else {
                    $dist = floor($max / 2) - (abs($thisSystem - $targetSystem) - ceil($max / 2));
                }
                return $dist * $config->system_distance_factor + $config->system_distance;
            } else {
                return abs($thisSystem - $targetSystem) * $config->system_distance_factor + $config->system_distance;
            }
        } elseif ($thisPlanet != $targetPlanet) {
            return abs($thisPlanet - $targetPlanet) * $config->planet_distance_factor + $config->planet_distance;
        }
        return 5;
    }

    /**
     * Travel time in seconds.
     * Keep scripts/game/flotten.js#GetRawDuration() and ConfigUni.tpl#getDuration() in sync.
     */
    public function getMissionDuration(int $SpeedFactor, int $MaxFleetSpeed, int $Distance, int $GameSpeed, array $USER): int
    {
        $duration = (3500 / ($SpeedFactor * 0.1));
        $duration *= pow($Distance * 10 / $MaxFleetSpeed, 0.5);
        $duration += 10;
        $duration /= $GameSpeed;

        if (isset($USER['factor']['FlyTime'])) {
            $duration *= max(0, 1 + $USER['factor']['FlyTime']);
        }

        return floor(max($duration, MIN_FLEET_TIME));
    }

    public function getMIPDuration(int $startSystem, int $targetSystem): int
    {
        $config = $this->configs->get();
        if ($config->galaxy_type == 2) {
            $Distance = 2;
        } elseif ($config->galaxy_type == 1) {
            $max = $config->max_system;
            if (abs($startSystem - $targetSystem) < ceil($max / 2)) {
                $Distance = abs($startSystem - $targetSystem);
            } else {
                $Distance = floor($max / 2) - (abs($startSystem - $targetSystem) - ceil($max / 2));
            }
        } else {
            $Distance = abs($startSystem - $targetSystem);
        }

        $Duration = max(round((30 + 60 * $Distance) / $this->getGameSpeedFactor()), MIN_FLEET_TIME);

        return floor($Duration);
    }

    public function getGameSpeedFactor(): int
    {
        return $this->configs->get()->fleet_speed / 2500;
    }

    public function getMaxFleetSlots(array $USER): int
    {
        $resource = & $this->state->resource;
        return 1 + $USER[$resource[108]];
    }

    public function getFleetRoom(array $Fleet): int
    {
        $pricelist = & $this->state->pricelist;
        $FleetRoom = 0;
        foreach ($Fleet as $ShipID => $amount) {
            $FleetRoom += $pricelist[$ShipID]['capacity'] * $amount;
        }
        return floor($FleetRoom);
    }

    /**
     * Speed of the slowest ship of the fleet (a single ship id counts as one ship of that type); 0 for an empty fleet.
     */
    public function getFleetMaxSpeed(array|int $Fleets, array $Player): int
    {
        if (empty($Fleets)) {
            return 0;
        }

        $FleetArray = (!is_array($Fleets)) ? [$Fleets => 1] : $Fleets;
        $speedalls = [];

        foreach ($FleetArray as $ShipID => $Count) {
            $speedalls[$ShipID] = $this->getShipSpeed($ShipID, $Player);
        }

        return min($speedalls);
    }

    /**
     * Deuterium consumption of the whole fleet for the given duration/distance (including the honor point boost, plus 1).
     */
    public function getFleetConsumption(array $FleetArray, int $MissionDuration, int $MissionDistance, array $Player, int $GameSpeed): float
    {
        $consumption = 0;

        foreach ($FleetArray as $ShipID => $Count) {
            $ShipSpeed = $this->getShipSpeed($ShipID, $Player);
            $ShipConsumption = $this->getShipConsumption($ShipID, $Player);

            $spd = 35000 / max($MissionDuration * $GameSpeed - 10, 1) * sqrt($MissionDistance * 10 / $ShipSpeed);
            $basicConsumption = $ShipConsumption * $Count;
            $consumption += $basicConsumption * $MissionDistance / 35000 * (($spd / 10) + 1) * (($spd / 10) + 1);
        }
        return (round($consumption * $this->playerUtil()->calculate_honorpoints_boost($Player['id'])) + 1);
    }

    public function getFleetShipInfo(array $FleetArray, array $Player): array
    {
        $FleetInfo = [];
        foreach ($FleetArray as $ShipID => $Amount) {
            $FleetInfo[$ShipID] = [
                'consumption' => $this->getShipConsumption($ShipID, $Player),
                'speed' => $this->getFleetMaxSpeed($ShipID, $Player),
                'amount' => floatToString($Amount),
            ];
        }
        return $FleetInfo;
    }

    public function getPhalanxRange($PhalanxLevel)
    {
        $config = $this->configs->get();
        return ($PhalanxLevel == 1) ? 0 : floor((pow($PhalanxLevel, 2) - 1) * $config->phalanx_range_factor);
    }
}
