<?php

namespace App\Game\Core;

/**
 * Pure production maths of a planet: eco hash, storage/energy/production-per-hour rebuild from the vars-table formulas
 * (via FormulaEvaluator) and the stock integration over a time span.
 *
 * Collaborators: Singleton (game data tables: ProdGrid, resource, reslist), Database (research network lookup only).
 * Works on plain planet/user arrays and returns the changed planet; the only DB access is getNetworkLevel() (read).
 * Used by ResourceUpdate, which owns the planet/user state and decides when to recalculate.
 */
class ResourceProduction
{
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Fingerprint of every input of the production rebuild (building levels and percentages, basic incomes,
     * multipliers, storage buildings, energy/plasma/... techs). Equal hash means perhour/max/energy are still valid.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     */
    public function createHash(array $user, array $planet, Config $config): string
    {
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $hash = [];
        foreach ($reslist['prod'] as $id) {
            $hash[] = $planet[$resource[$id]];
            $hash[] = $planet[$resource[$id] . '_porcent'];
        }

        $ressource = array_merge([], $reslist['resstype'][1], $reslist['resstype'][2]);
        foreach ($ressource as $id) {
            $hash[] = $config->{$resource[$id] . '_basic_income'};
        }

        $hash[] = $config->resource_multiplier;
        $hash[] = $config->storage_multiplier;
        $hash[] = $config->energy_multiplier;
        $hash[] = $planet[$resource[22]];
        $hash[] = $planet[$resource[23]];
        $hash[] = $planet[$resource[24]];
        $hash[] = $user[$resource[113]];
        $hash[] = $user[$resource[131]];
        $hash[] = $user[$resource[132]];
        $hash[] = $user[$resource[133]];

        return md5(implode('::', $hash));
    }

    /**
     * Recomputes storage capacity (metal/crystal/deuterium _max), energy and energy_used and the hourly production
     * (metal/crystal/deuterium _perhour) of a planet from the building levels, the user's techs and the universe config.
     *
     * Game rules: energy shortage throttles every producer by min(1, energy / energy_used); with no consumers the
     * production is not throttled; the planet resource bonus applies only if config planet_ressource_bonus is set;
     * production techs (131-133) add 2 % per level; consumption (negative production) is never throttled or boosted.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array<string, mixed> the planet with the recomputed fields
     * @throws FormulaException when a stored formula is invalid
     */
    public function rebuild(array $user, array $planet, Config $config): array
    {
        $resource = & $this->state->resource;

        // Moons get no basic income: produce() returns early for them, the shared config stays untouched.
        $temp = $this->sumBuildings($user, $planet);

        $planet['metal_max'] = $temp[901]['max'] * $config->storage_multiplier;
        $planet['crystal_max'] = $temp[902]['max'] * $config->storage_multiplier;
        $planet['deuterium_max'] = $temp[903]['max'] * $config->storage_multiplier;

        $planet['energy'] = round($temp[911]['plus'] * $config->energy_multiplier);
        $planet['energy_used'] = $temp[911]['minus'] * $config->energy_multiplier;
        // Without energy consumers (all mines off) there is nothing to throttle, but the fusion plant still burns its
        // deuterium (like OGame, the reactor runs at its set level regardless of consumers), so production is not zeroed.
        $energyUsed = abs($planet['energy_used']);
        $prodLevel = $energyUsed > 0 ? min(1, $planet['energy'] / $energyUsed) : 1;

        $metalBonus = 1;
        $crystalBonus = 1;
        $deuteriumBonus = 1;
        if ($config->planet_ressource_bonus) {
            $metalBonus = 1 + $planet['metal_bonus_percent'] / 100;
            $crystalBonus = 1 + $planet['crystal_bonus_percent'] / 100;
            $deuteriumBonus = 1 + $planet['deuterium_bonus_percent'] / 100;
        }

        // $temp[x]['plus'] regular growth, (1 + 0.02 * tech) maximiser, $prodLevel energy factor on deficit,
        // $temp[x]['minus'] consumption (deuterium), resource_multiplier economy speed
        $planet['metal_perhour'] = (
            ($temp[901]['plus'] * $metalBonus) * (1 + 0.02 * $user[$resource[131]]) * $prodLevel + $temp[901]['minus']
        ) * ($config->resource_multiplier / 2500);

        $planet['crystal_perhour'] = (
            ($temp[902]['plus'] * $crystalBonus) * (1 + 0.02 * $user[$resource[132]]) * $prodLevel + $temp[902]['minus']
        ) * ($config->resource_multiplier / 2500);

        $planet['deuterium_perhour'] = (
            ($temp[903]['plus'] * $deuteriumBonus) * (1 + 0.02 * $user[$resource[133]]) * $prodLevel + $temp[903]['minus']
        ) * ($config->resource_multiplier / 2500);

        return $planet;
    }

    /**
     * Integrates the stock over $productionTime seconds (basic income + perhour, capped by storage * max_overflow,
     * consumption floors at 0). Moons (type 3) produce nothing.
     *
     * @param array<string, mixed> $planet
     * @param array<int, float|int> $builded running delta per resource id, extended by the produced amounts
     * @return array<string, mixed> the planet with the new stock
     */
    public function produce(array $planet, int|float $productionTime, Config $config, array &$builded): array
    {
        if ($planet['planet_type'] == 3) {
            return $planet;
        }

        $maxMetalStorage = $planet['metal_max'] * $config->max_overflow;
        $maxCristalStorage = $planet['crystal_max'] * $config->max_overflow;
        $maxDeuteriumStorage = $planet['deuterium_max'] * $config->max_overflow;

        $metalTheoretical = $productionTime * (
            ($config->metal_basic_income * ($config->resource_multiplier / 2500))
                + $planet['metal_perhour']
        ) / 3600;

        $this->addProduction($planet, $builded, RESOURCE_METAL, $metalTheoretical, $maxMetalStorage);

        $cristalTheoretical = $productionTime * (
            ($config->crystal_basic_income * ($config->resource_multiplier / 2500))
                + $planet['crystal_perhour']
        ) / 3600;

        $this->addProduction($planet, $builded, RESOURCE_CRYSTAL, $cristalTheoretical, $maxCristalStorage);

        $deuteriumTheoretical = $productionTime * (
            ($config->deuterium_basic_income * ($config->resource_multiplier / 2500))
                + $planet['deuterium_perhour']
        ) / 3600;

        $this->addProduction($planet, $builded, RESOURCE_DEUT, $deuteriumTheoretical, $maxDeuteriumStorage);

        return $planet;
    }

    /**
     * Levels of the research labs that count for a research on $planet: the planet's own lab plus the highest labs of
     * the user's other planets, as many as the intergalactic research network tech (123) allows.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return list<int|string>
     */
    public function getNetworkLevel(array $user, array $planet): array
    {
        $resource = & $this->state->resource;

        $researchLevelList = [$planet[$resource[31]]];
        if ($user[$resource[123]] > 0) {
            $sql = 'SELECT ' . $resource[31]
                    . ' FROM %%PLANETS%% WHERE id != :planetId AND id_owner = :userId AND destruyed = 0 ORDER BY '
                    . $resource[31] . ' DESC LIMIT :limit;';

            $researchResult = $this->db->select($sql, [
                ':limit'    => (int) $user[$resource[123]],
                ':planetId' => $planet['id'],
                ':userId'   => $user['id'],
            ]);

            foreach ($researchResult as $researchRow) {
                $researchLevelList[] = $researchRow[$resource[31]];
            }
        }

        return $researchLevelList;
    }

    /**
     * Evaluates a production/storage formula of the vars table with the four variables formulas may use
     * (see FormulaEvaluator for the grammar; compiled once per process, no eval()).
     *
     * @throws FormulaException when the stored formula is invalid
     */
    private function runFormula(string $formula, mixed $BuildLevel, mixed $BuildTemp, mixed $BuildEnergy, mixed $BuildLevelFactor): mixed
    {
        return FormulaEvaluator::evaluate($formula, compact('BuildLevel', 'BuildTemp', 'BuildEnergy', 'BuildLevelFactor'));
    }

    /**
     * Sums the storage capacity per resource and the production/consumption per resource and energy over all
     * storage and production buildings.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $planet
     * @return array<int, array<string, float|int>> keyed by 901/902/903 (max, plus, minus) and 911 (plus, minus)
     */
    private function sumBuildings(array $user, array $planet): array
    {
        $ProdGrid = & $this->state->ProdGrid;
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;

        $temp = [
            901 => ['max' => 0, 'plus' => 0, 'minus' => 0],
            902 => ['max' => 0, 'plus' => 0, 'minus' => 0],
            903 => ['max' => 0, 'plus' => 0, 'minus' => 0],
            911 => ['plus' => 0, 'minus' => 0],
        ];

        /* Data for the formulas */
        $BuildTemp = $planet['temp_max'];
        $BuildEnergy = $user[$resource[113]];

        foreach ($reslist['storage'] as $ProdID) {
            foreach ($reslist['resstype'][1] as $ID) {
                if (!isset($ProdGrid[$ProdID]['storage'][$ID])) {
                    continue;
                }

                $BuildLevel = $planet[$resource[$ProdID]];
                $temp[$ID]['max'] += round($this->runFormula(
                    $ProdGrid[$ProdID]['storage'][$ID],
                    $BuildLevel,
                    $BuildTemp,
                    $BuildEnergy,
                    null,
                ));
            }
        }

        $ressIDs = array_merge([], $reslist['resstype'][1], $reslist['resstype'][2]);

        foreach ($reslist['prod'] as $ProdID) {
            $BuildLevelFactor = $planet[$resource[$ProdID] . '_porcent'];
            $BuildLevel = $planet[$resource[$ProdID]];

            foreach ($ressIDs as $ID) {
                if (!isset($ProdGrid[$ProdID]['production'][$ID])) {
                    continue;
                }

                $Production = $this->runFormula(
                    $ProdGrid[$ProdID]['production'][$ID],
                    $BuildLevel,
                    $BuildTemp,
                    $BuildEnergy,
                    $BuildLevelFactor,
                );

                if ($Production > 0) {
                    $temp[$ID]['plus'] += $Production;
                } else {
                    // Consumption (fusion plant deuterium) is always counted, even at a stock of 0: the stock is not part
                    // of the eco hash, so skipping it here froze a wrong production until the next hash change. A
                    // negative net production simply keeps the stock at 0 (see addProduction).
                    $temp[$ID]['minus'] += $Production;
                }
            }
        }

        return $temp;
    }

    /**
     * Adds $produced of one resource to the planet stock, clamped: consumption never takes the stock below 0, production
     * never pushes it above $storage, and a stock already above storage stays as it is.
     *
     * @param array<string, mixed> $planet
     * @param array<int, float|int> $builded
     */
    private function addProduction(array &$planet, array &$builded, int $resourceType, int|float $produced, int|float $storage): void
    {
        $resource = & $this->state->resource;

        if ($produced < 0) {
            if ($planet[$resource[$resourceType]] + $produced < 0) {
                // consumption larger than the stock empties it exactly (was 1 + the fraction left over)
                $produced = -$planet[$resource[$resourceType]];
            }
        } elseif ($planet[$resource[$resourceType]] <= $storage) {
            if ($planet[$resource[$resourceType]] + $produced > $storage) {
                $produced = $storage - $planet[$resource[$resourceType]];
            }
        } else {
            $produced = 0;
        }

        $builded[$resourceType] += $produced;
        $planet[$resource[$resourceType]] += $produced;
    }
}
