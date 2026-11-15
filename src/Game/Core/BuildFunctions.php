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

use Exception;

class BuildFunctions
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public static array $bonusList = [
        'Attack',
        'Defensive',
        'Shield',
        'BuildTime',
        'ResearchTime',
        'ShipTime',
        'DefensiveTime',
        'Resource',
        'Energy',
        'ResourceStorage',
        'ShipStorage',
        'FlyTime',
        'FleetSlots',
        'Planets',
        'SpyPower',
        'Expedition',
        'GateCoolTime',
        'MoreFound',
    ];

    public function getBonusList(): array
    {
        return self::$bonusList;
    }

    public function getRestPrice(array $USER, array $PLANET, int $Element, ?array $elementPrice = null): array
    {
        $resource = & $this->state->resource;

        if (!isset($elementPrice)) {
            $elementPrice = $this->getElementPrice($USER, $PLANET, $Element);
        }

        $overflow = [];

        foreach ($elementPrice as $resType => $resPrice) {
            $available = isset($PLANET[$resource[$resType]])
                ? $PLANET[$resource[$resType]] : $USER[$resource[$resType]];
            $overflow[$resType] = max($resPrice - floor($available), 0);
        }

        return $overflow;
    }

    public function getElementPrice(array $USER, array $PLANET, int $Element, bool $forDestroy = false, ?int $forLevel = null): array
    {
        $pricelist = & $this->state->pricelist;
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;

        $catchUpFactor = $this->playerUtil()->getCatchUpFactor($USER);
        if (
            in_array($Element, $reslist['fleet']) || in_array($Element, $reslist['defense'])
            || in_array($Element, $reslist['missile'])
        ) {
            $elementLevel = $forLevel ?? 0;
            $catchUpFactor = 1.0; // no reduction for fleet/defence/missiles
        } elseif (isset($forLevel)) {
            $elementLevel = $forLevel;
        } elseif (isset($PLANET[$resource[$Element]])) {
            $elementLevel = $PLANET[$resource[$Element]];
        } elseif (isset($USER[$resource[$Element]])) {
            $elementLevel = $USER[$resource[$Element]];
        } else {
            return [];
        }
        $catchUpFactor = $this->playerUtil()->getCatchUpFactorForElement($catchUpFactor, $Element, $elementLevel);

        $price = [];
        foreach ($reslist['ressources'] as $resType) {
            if (!isset($pricelist[$Element]['cost'][$resType])) {
                continue;
            }
            $ressourceAmount = $pricelist[$Element]['cost'][$resType];

            if ($ressourceAmount == 0) {
                continue;
            }

            if ($resType != RESOURCE_ENERGY) {
                $price[$resType] = $ressourceAmount * $catchUpFactor;
            } else {
                $price[$resType] = $ressourceAmount;
            }

            if (
                isset($pricelist[$Element]['factor']) && $pricelist[$Element]['factor'] != 0
                && $pricelist[$Element]['factor'] != 1
            ) {
                $price[$resType] *= pow($pricelist[$Element]['factor'], $elementLevel - 1);
            }

            if (
                $forLevel && (in_array($Element, $reslist['fleet']) || in_array($Element, $reslist['defense'])
                    || in_array($Element, $reslist['missile']))
            ) {
                $price[$resType] *= $elementLevel;
            }

            if ($forDestroy === true) {
                $price[$resType]    /= 2;
            }
        }

        return $price;
    }

    public function isTechnologieAccessible(array $USER, array $PLANET, int $Element): bool
    {
        $requeriments = & $this->state->requeriments;
        $resource = & $this->state->resource;

        if (!isset($requeriments[$Element])) {
            return true;
        }

        foreach ($requeriments[$Element] as $ReqElement => $EleLevel) {
            if (
                (isset($USER[$resource[$ReqElement]]) && $USER[$resource[$ReqElement]] < $EleLevel) ||
                (isset($PLANET[$resource[$ReqElement]]) && $PLANET[$resource[$ReqElement]] < $EleLevel)
            ) {
                return false;
            }
        }
        return true;
    }

    /**
     * Checks if an element is enabled in the current universe.
     * Buldings, ships etc are enabled for all universes via table vars.
     * To disable individual elements one can extend the switch cases and check e.g. for an active module.
     *
     * @param integer $elementID
     * @return boolean
     */
    public function isEnabled(int $elementID): bool
    {
        switch ($elementID) {
            case REPAIR_DOCK:
                return $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK);
            default:
                return true;
        }
    }

    public function getBuildingTime(
        array $USER,
        array $PLANET,
        int $Element,
        ?array $elementPrice = null,
        bool $forDestroy = false,
        ?int $forLevel = null,
        int $maxQueueIndex = 0
    ): float
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $requeriments = & $this->state->requeriments;
        $config = $this->configs->get($USER['universe']);

        $time = 0;

        if (!isset($elementPrice)) {
            $elementPrice = $this->getElementPrice($USER, $PLANET, $Element, $forDestroy, $forLevel);
        }

        $elementCost = 0;

        if (isset($elementPrice[RESOURCE_METAL])) {
            $elementCost += $elementPrice[RESOURCE_METAL];
        }

        if (isset($elementPrice[RESOURCE_CRYSTAL])) {
            $elementCost += $elementPrice[RESOURCE_CRYSTAL];
        }

        if (in_array($Element, $reslist['build'])) {
            $maxQueueIndex = max(0, $maxQueueIndex);
            $robo = $PLANET[$resource[14]];
            $nani = $PLANET[$resource[15]];
            $CurrentQueue = !empty($PLANET['b_building_id']) ? unserialize($PLANET['b_building_id']) : [];
            if (!empty($CurrentQueue) && count($CurrentQueue) != 0) {
                foreach ($CurrentQueue as $index => $QueueSubArray) {
                    if ($index >= $maxQueueIndex) {
                        break;
                    }
                    if ($QueueSubArray[0] == ROBOT_FACTORY) {
                        if ($QueueSubArray[4] == 'build') {
                            $robo++;
                        } else {
                            $robo--;
                        }
                    } elseif ($QueueSubArray[0] == NANITE_FACTORY) {
                        if ($QueueSubArray[4] == 'build') {
                            $nani++;
                        } else {
                            $nani--;
                        }
                    }
                }
            }
            $time = $elementCost / ($config->building_speed * (1 + $robo))
                * pow(0.5, $nani);
        } elseif (in_array($Element, $reslist['fleet'])) {
            $time = $elementCost / ($config->shipyard_speed * (1 + $PLANET[$resource[SHIPYARD]]))
                * pow(0.5, $PLANET[$resource[NANITE_FACTORY]]);
        } elseif (in_array($Element, $reslist['defense'])) {
            $time = $elementCost / ($config->shipyard_speed * (1 + $PLANET[$resource[SHIPYARD]]))
                * pow(0.5, $PLANET[$resource[NANITE_FACTORY]]);
        } elseif (in_array($Element, $reslist['missile'])) {
            $time = $elementCost / ($config->shipyard_speed * (1 + $PLANET[$resource[SHIPYARD]]))
                * pow(0.5, $PLANET[$resource[NANITE_FACTORY]]);
        } elseif (in_array($Element, $reslist['tech'])) {
            if (is_numeric($PLANET[$resource[RESEARCH_LABORATORY] . '_inter'])) {
                $Level = $PLANET[$resource[RESEARCH_LABORATORY]];
            } else {
                $Level = 0;
                foreach ($PLANET[$resource[RESEARCH_LABORATORY] . '_inter'] as $Levels) {
                    if (!isset($requeriments[$Element][RESEARCH_LABORATORY]) || $Levels >= $requeriments[$Element][RESEARCH_LABORATORY]) {
                        $Level += $Levels;
                    }
                }
            }

            $time = $elementCost / (1000 * (1 + $Level)) / ($config->research_speed / 2500)
                * pow(1 - $config->factor_university / 100, $PLANET[$resource[6]]);
        }

        if ($forDestroy) {
            $time = floor($time * 1300);
        } else {
            $time = floor($time * 3600);
        }

        return max($time, $config->min_build_time);
    }

    public function isElementBuyable(
        array $USER,
        array $PLANET,
        int $Element,
        ?array $elementPrice = null
    ): bool
    {
        $rest = $this->getRestPrice($USER, $PLANET, $Element, $elementPrice);
        return count(array_filter($rest)) === 0;
    }

    public function getMaxConstructibleElements(array $USER, array $PLANET, int $Element, ?array $elementPrice = null): int
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        if (!isset($elementPrice)) {
            $elementPrice = $this->getElementPrice($USER, $PLANET, $Element);
        }

        $maxElement = [];

        foreach ($elementPrice as $resourceID => $price) {
            if (isset($PLANET[$resource[$resourceID]])) {
                $maxElement[] = floor($PLANET[$resource[$resourceID]] / $price);
            } elseif (isset($USER[$resource[$resourceID]])) {
                $maxElement[] = floor($USER[$resource[$resourceID]] / $price);
            } else {
                throw new Exception("Unknown Ressource " . $resourceID . " at element " . $Element . ".");
            }
        }

        if (in_array($Element, $reslist['one'])) {
            $maxElement[] = 1;
        }

        return min($maxElement);
    }

    public function getMaxConstructibleRockets(array $PLANET, ?array $Missiles = null): array
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        if (!isset($Missiles)) {
            $Missiles = [];

            foreach ($reslist['missile'] as $elementID) {
                $Missiles[$elementID] = $PLANET[$resource[$elementID]];
            }
        }

        $BuildArray = !empty($PLANET['b_hangar_id']) ? unserialize($PLANET['b_hangar_id']) : [];
        $MaxMissiles = $PLANET[$resource[SILO]] * 10 * max($this->configs->get()->silo_factor, 1);

        foreach ($BuildArray as $ElementArray) {
            if (isset($Missiles[$ElementArray[0]])) {
                $Missiles[$ElementArray[0]] += $ElementArray[1];
            }
        }

        $ActuMissiles = $Missiles[INTERCEPTOR_MISSILE] + (2 * $Missiles[INTERPLANETARY_MISSILE]);
        $MissilesSpace = max(0, $MaxMissiles - $ActuMissiles);

        return [
            INTERCEPTOR_MISSILE => $MissilesSpace,
            INTERPLANETARY_MISSILE => floor($MissilesSpace / 2),
        ];
    }

    public function getType(int $Element): string
    {
        $reslist = & $this->state->reslist;

        if (in_array($Element, $reslist['ship_type']['civil'])) {
            return "civil";
        } elseif (in_array($Element, $reslist['ship_type']['military'])) {
            return "military";
        } elseif (in_array($Element, $reslist['ship_type']['recycler'])) {
            return "recycler";
        }
        return "civil";
    }

    /**
     * Project occupied and maximum fields after applying a building queue.
     *
     * Terraformers and Moon Bases occupy a field like any other building; their
     * extra fields are only available to later queue entries.
     *
     * Queue item format: [elementId, level, time, endTime, mode, cost?]
     *
     * @return array{current: int, max: int, valid: bool}
     */
    public function getQueueFieldProjection(array $PLANET, array $queue): array
    {
        $resource = & $this->state->resource;
        $current = (int) $PLANET['field_current'];
        $projected = $PLANET;
        $valid = true;

        foreach ($queue as $item) {
            $element = (int) $item[0];
            $level = (int) $item[1];
            $mode = $item[4] ?? 'build';

            if ($mode === 'destroy') {
                $current--;
                // Destroyed expanders must drop their bonus before later items run.
                if ($element === TERRAFORMER || $element === MOONBASE) {
                    $projected[$resource[$element]] = max(0, $level - 1);
                }
                continue;
            }

            $max = (int) $this->playerUtil()->calculateMaxPlanetFields($projected);
            if ($current >= $max) {
                $valid = false;
                break;
            }

            $current++;
            if ($element === TERRAFORMER || $element === MOONBASE) {
                $projected[$resource[$element]] = $level;
            }
        }

        return [
            'current' => $current,
            'max' => (int) $this->playerUtil()->calculateMaxPlanetFields($projected),
            'valid' => $valid,
        ];
    }

    /**
     * Whether another building can be queued (or started) without exceeding fields.
     *
     * Queued Terraformers and Moon Bases increase later capacity, but they still
     * need a free field of their own at the point they are built.
     */
    public function canQueueBuildingOnFields(array $PLANET, array $queue, bool $addMode = true): bool
    {
        if (!$addMode) {
            return true;
        }

        $projection = $this->getQueueFieldProjection($PLANET, $queue);

        return $projection['valid'] && $projection['current'] < $projection['max'];
    }
}
