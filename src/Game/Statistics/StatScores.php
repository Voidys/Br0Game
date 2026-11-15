<?php

namespace App\Game\Statistics;

use App\Game\Core\ConfigRegistry;

/**
 * Score formulas of the statistics: research, building, defense and fleet points of a player or planet from the element
 * levels/amounts and the price lists of its universe. Pure calculation (no database access); the score is the total
 * resource cost divided by the `stat_settings` config value (points per 1000 resource units), building and research
 * level costs memoized.
 */
class StatScores
{
    /**
     * @param array<int, array<int, string>>           $resources  universe => element id => column name
     * @param array<int, array<string, array<int>>>    $reslists   universe => list name => element ids
     * @param array<int, array<int, array<string, mixed>>> $pricelists universe => element id => price data
     */
    public function __construct(
        private readonly ConfigRegistry $configs,
        private readonly array $resources,
        private readonly array $reslists,
        private readonly array $pricelists,
    ) {}

    /** @var array<int, array<int, array<int, float|int>>> cost of an element level by universe, element, level */
    private array $levelCosts = [];

    /** Total cost of building/researching $element up to $level (level $level only), memoized. */
    private function levelCost(int $uni, int $element, int $level): float|int
    {
        return $this->levelCosts[$uni][$element][$level] ??= (
            $this->pricelists[$uni][$element]['cost'][901]
            + $this->pricelists[$uni][$element]['cost'][902]
            + $this->pricelists[$uni][$element]['cost'][903]
        ) * pow($this->pricelists[$uni][$element]['factor'], $level - 1);
    }

    /**
     * Research score of a player: total cost of all tech levels (each level costs factor^(level-1) times the base cost)
     * divided by the points-per-score setting (stat_settings).
     *
     * @param array<string, mixed> $USER player row with the tech level columns and `universe`
     * @return array{count: int|float, points: int|float} number of levels, score
     */
    public function researchByUser(array $USER): array
    {
        $resource = $this->resources[$USER['universe']];
        $reslist = $this->reslists[$USER['universe']];
        $pricelist = $this->pricelists[$USER['universe']];
        $TechCounts = 0;
        $TechPoints = 0;

        foreach ($reslist['tech'] as $Techno) {
            if ($USER[$resource[$Techno]] == 0) {
                continue;
            }

            // PointsPerCot == Config::get()->stat_settings
            for ($i = 1; $i <= $USER[$resource[$Techno]]; $i++) {
                $TechPoints += $this->levelCost($USER['universe'], $Techno, $i);
            }
            $TechCounts += $USER[$resource[$Techno]];
        }

        return ['count' => $TechCounts, 'points' => ($TechPoints / $this->configs->get()->stat_settings)];
    }

    /**
     * Building score of a planet: total cost of all building levels divided by stat_settings.
     *
     * @param array<string, mixed> $PLANET planet row with the building columns and `universe`
     * @return array{count: int|float, points: int|float} number of levels, score
     */
    public function buildingByPlanet(array $PLANET): array
    {
        $resource = $this->resources[$PLANET['universe']];
        $reslist = $this->reslists[$PLANET['universe']];
        $pricelist = $this->pricelists[$PLANET['universe']];
        $BuildCounts = 0;
        $BuildPoints = 0;

        foreach ($reslist['build'] as $Build) {
            if ($PLANET[$resource[$Build]] == 0) {
                continue;
            }

            // PointsPerCost == Config::get()->stat_settings
            for ($i = 1; $i <= $PLANET[$resource[$Build]]; $i++) {
                $BuildPoints += $this->levelCost($PLANET['universe'], $Build, $i);
            }
            $BuildCounts += $PLANET[$resource[$Build]];
        }
        return ['count' => $BuildCounts, 'points' => ($BuildPoints / $this->configs->get()->stat_settings)];
    }

    /**
     * Defense score of a player: cost of all defense and missile units divided by stat_settings.
     *
     * @param array<string, mixed> $USER player row with the unit columns (summed over all planets) and `universe`
     * @return array{count: int|float, points: int|float} number of units, score
     */
    public function defenseByUser(array $USER): array
    {
        $resource = $this->resources[$USER['universe']];
        $reslist = $this->reslists[$USER['universe']];
        $pricelist = $this->pricelists[$USER['universe']];
        $DefenseCounts = 0;
        $DefensePoints = 0;

        foreach (array_merge($reslist['defense'], $reslist['missile']) as $Defense) {
            if ($USER[$resource[$Defense]] == 0) {
                continue;
            }

            $Units = $pricelist[$Defense]['cost'][901] + $pricelist[$Defense]['cost'][902] + $pricelist[$Defense]['cost'][903];
            $DefensePoints += $Units * $USER[$resource[$Defense]];
            $DefenseCounts += $USER[$resource[$Defense]];
        }

        return ['count' => $DefenseCounts, 'points' => ($DefensePoints / $this->configs->get()->stat_settings)];
    }

    /**
     * Fleet score of a player: cost of all ships (on planets and flying) divided by stat_settings.
     *
     * @param array<string, mixed> $USER player row with the ship columns (including flying fleets) and `universe`
     * @return array{count: int|float, points: int|float} number of ships, score
     */
    public function fleetByUser(array $USER): array
    {
        $resource = $this->resources[$USER['universe']];
        $reslist = $this->reslists[$USER['universe']];
        $pricelist = $this->pricelists[$USER['universe']];
        $FleetCounts = 0;
        $FleetPoints = 0;

        foreach ($reslist['fleet'] as $Fleet) {
            if ($USER[$resource[$Fleet]] == 0) {
                continue;
            }

            $Units = $pricelist[$Fleet]['cost'][901] + $pricelist[$Fleet]['cost'][902] + $pricelist[$Fleet]['cost'][903];
            $FleetPoints += $Units * $USER[$resource[$Fleet]];
            $FleetCounts += $USER[$resource[$Fleet]];
        }

        return ['count' => $FleetCounts, 'points' => ($FleetPoints / $this->configs->get()->stat_settings)];
    }
}
