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
use App\Game\Core\FormulaEvaluator;
use App\Game\Core\ResourceUpdate;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/** Resource settings: production table of the current planet and production levels (modes: show, send). */
#[\App\Routing\GamePage('game.php', 'resources')]
#[\App\Routing\GamePage('game.php', 'resources', uniPrefix: true)]
class ResourcesController extends AbstractGameController
{
    public static $requireModule = 'MODULE_RESSOURCE_LIST';

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Saves the production levels (POST prod[<resource id>] = 0..10 in steps of 10%). Not in vacation mode. Locks the planet row, only
     * accepts columns `<resource>_porcent` that exist on the planet, updates them and clears `eco_hash` so the production is recalculated.
     * Redirects back.
     */
    public function send()
    {
        $resource = $this->state->resource;
        if ($this->state->USER['urlaubs_modus'] == 0) {
            $productionValues = [];
            if (!isset($this->state->requestPost['prod'])) {
                $this->state->requestPost['prod'] = [];
            }

            $db = $this->db;
            $db->startTransaction();

            $param = [':planetId' => $this->state->PLANET['id']];
            $sql = "SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;";
            $this->state->PLANET = $db->selectSingle($sql, $param);

            foreach ($this->state->requestPost['prod'] as $resourceId => $Value) {
                $FieldName = $resource[$resourceId] . '_porcent';
                if (!isset($this->state->PLANET[$FieldName]) || !in_array($Value, range(0, 10))) {
                    continue;
                }

                $productionValues[$FieldName] = (int) $Value;
                $this->state->PLANET[$FieldName] = (int) $Value;
            }

            if (!empty($productionValues)) {
                $db->updateRows('PLANETS', $productionValues, ['id' => $this->state->PLANET['id']], ['eco_hash' => "''"]);
                $this->state->PLANET['eco_hash'] = '';

                [$this->state->USER, $this->state->PLANET] = $this->ecoObj->CalcResource($this->state->USER, $this->state->PLANET, true, $this->state->runtime->timestamp + 1);
            }

            $db->commit();
        }

        $this->redirectTo('game?page=resources');
    }

    /**
     * Resource settings page: per-resource production breakdown of the current planet (plus, minus, bonus), storage capacity and the production level
     * selectors saved by send().
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $ProdGrid = $this->state->ProdGrid;
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;

        $config = $this->configs->get();

        if ($this->state->USER['urlaubs_modus'] == 1 || $this->state->PLANET['planet_type'] != 1) {
            $basicIncome[RESOURCE_METAL] = 0;
            $basicIncome[RESOURCE_CRYSTAL] = 0;
            $basicIncome[RESOURCE_DEUT] = 0;
            $basicIncome[RESOURCE_ENERGY] = 0;
        } else {
            $basicIncome[RESOURCE_METAL] = $config->{$resource[RESOURCE_METAL] . '_basic_income'};
            $basicIncome[RESOURCE_CRYSTAL] = $config->{$resource[RESOURCE_CRYSTAL] . '_basic_income'};
            $basicIncome[RESOURCE_DEUT] = $config->{$resource[RESOURCE_DEUT] . '_basic_income'};
            $basicIncome[RESOURCE_ENERGY] = $config->{$resource[RESOURCE_ENERGY] . '_basic_income'};
        }

        $temp = [
            RESOURCE_METAL => [
                'plus'  => 0,
                'minus' => 0,
            ],
            RESOURCE_CRYSTAL => [
                'plus'  => 0,
                'minus' => 0,
            ],
            RESOURCE_DEUT => [
                'plus'  => 0,
                'minus' => 0,
            ],
            RESOURCE_ENERGY => [
                'plus'  => 0,
                'minus' => 0,
                'bonus' => 0,
            ],
        ];
        $ressIDs = array_merge([], $reslist['resstype'][1], $reslist['resstype'][2]);

        $productionList = [];

        if ($this->state->PLANET['energy_used'] != 0) {
            $prodLevel = min(1, $this->state->PLANET['energy'] / abs($this->state->PLANET['energy_used']));
        } else {
            $prodLevel = 0;
        }

        /* Data for eval */
        $BuildEnergy = $this->state->USER[$resource[ENERGY_TECH]];
        $BuildTemp = $this->state->PLANET['temp_max'];

        $prodSelector = [];

        foreach ($reslist['prod'] as $ProdID) {
            if (isset($this->state->PLANET[$resource[$ProdID]]) && $this->state->PLANET[$resource[$ProdID]] == 0) {
                continue;
            }

            if (isset($this->state->USER[$resource[$ProdID]]) && $this->state->USER[$resource[$ProdID]] == 0) {
                continue;
            }

            $productionList[$ProdID] = [
                'production'    => [
                    RESOURCE_METAL => 0,
                    RESOURCE_CRYSTAL => 0,
                    RESOURCE_DEUT => 0,
                    RESOURCE_ENERGY => 0,
                ],
                'elementLevel'  => $this->state->PLANET[$resource[$ProdID]],
                'prodLevel'     => $this->state->PLANET[$resource[$ProdID] . '_porcent'],
            ];

            /* Data for eval */
            $BuildLevel = $this->state->PLANET[$resource[$ProdID]];
            $BuildLevelFactor = $this->state->PLANET[$resource[$ProdID] . '_porcent'];

            foreach ($ressIDs as $ID) {
                if (!isset($ProdGrid[$ProdID]['production'][$ID])) {
                    continue;
                }

                $Production = FormulaEvaluator::evaluate($ProdGrid[$ProdID]['production'][$ID], compact('BuildLevel', 'BuildLevelFactor', 'BuildEnergy', 'BuildTemp'));

                if (in_array($ID, $reslist['resstype'][2])) {
                    $Production *= $config->energy_multiplier;
                } else {
                    $Production *= $prodLevel * ($config->resource_multiplier / 2500);
                }

                if ($ProdID == fusion_plant && $ID == RESOURCE_ENERGY) {
                    $BuildEnergy = 0;
                    $bonus = FormulaEvaluator::evaluate($ProdGrid[$ProdID]['production'][$ID], compact('BuildLevel', 'BuildLevelFactor', 'BuildEnergy', 'BuildTemp'));
                    $temp[RESOURCE_ENERGY]['bonus'] = $Production - $bonus;
                    $BuildEnergy = $this->state->USER[$resource[ENERGY_TECH]];
                }

                $productionList[$ProdID]['production'][$ID] = $Production;

                if ($Production > 0) {
                    if ($this->state->PLANET[$resource[$ID]] == 0) {
                        continue;
                    }

                    $temp[$ID]['plus'] += $Production;
                } else {
                    $temp[$ID]['minus'] += $Production;
                }
            }

            $BuildEnergy = $this->state->USER[$resource[ENERGY_TECH]];
            $BuildLevelFactor = 10;
            $BuildLevel = $this->state->PLANET[$resource[$ProdID]];

            $energyBaseProduction = FormulaEvaluator::evaluate($ProdGrid[$ProdID]['production'][RESOURCE_ENERGY], compact('BuildLevel', 'BuildLevelFactor', 'BuildEnergy', 'BuildTemp')) * $config->energy_multiplier / 100 ;

            foreach (range(10, 0) as $percent) {
                $percentValue = $percent * 10;
                $percentLabel = $percentValue . '%';

                $value = round($energyBaseProduction * $percentValue - $productionList[$ProdID]['production'][RESOURCE_ENERGY]);

                //percent label left aligned (4 digits), value right aligned (7 digits)
                $prodSelector[$ProdID][$percent] = sprintf(
                    "%-4s %7s",
                    $percentLabel,
                    ($value == 0.0 ? '' : ($value > 0 ? '+' : '') . $this->viewHelpers()->prettyNumber($value)),
                );

                $prodSelector[$ProdID][$percent] = str_replace(' ', '&nbsp;', $prodSelector[$ProdID][$percent]);
            }
        }

        $storage = [
            RESOURCE_METAL => $this->viewHelpers()->shortlyNumber($this->state->PLANET[$resource[RESOURCE_METAL] . '_max']),
            RESOURCE_CRYSTAL => $this->viewHelpers()->shortlyNumber($this->state->PLANET[$resource[RESOURCE_CRYSTAL] . '_max']),
            RESOURCE_DEUT => $this->viewHelpers()->shortlyNumber($this->state->PLANET[$resource[RESOURCE_DEUT] . '_max']),
        ];

        $basicProduction = [
            RESOURCE_METAL => $basicIncome[RESOURCE_METAL] * ($config->resource_multiplier / 2500),
            RESOURCE_CRYSTAL => $basicIncome[RESOURCE_CRYSTAL] * ($config->resource_multiplier / 2500),
            RESOURCE_DEUT => $basicIncome[RESOURCE_DEUT] * ($config->resource_multiplier / 2500),
            RESOURCE_ENERGY => $basicIncome[RESOURCE_ENERGY] * $config->energy_multiplier,
        ];

        $universe_config = $this->configs->get($this->universe()->current());
        $positionBasedRessourceBonus = $universe_config->planet_ressource_bonus;

        $metal_bonus_percent     = 0;
        $crystal_bonus_percent   = 0;
        $deuterium_bonus_percent = 0;

        $bonusProductionPositionBased = [];


        if (
            $positionBasedRessourceBonus) {

            $metal_bonus_percent     = $this->state->PLANET['metal_bonus_percent'] / 100;
            $crystal_bonus_percent   = $this->state->PLANET['crystal_bonus_percent'] / 100;
            $deuterium_bonus_percent = $this->state->PLANET['deuterium_bonus_percent'] / 100;

            $bonusProductionPositionBased = [
                RESOURCE_METAL => $temp[RESOURCE_METAL]['plus'] * $metal_bonus_percent,
                RESOURCE_CRYSTAL => $temp[RESOURCE_CRYSTAL]['plus'] * $crystal_bonus_percent,
                RESOURCE_DEUT => $temp[RESOURCE_DEUT]['plus'] * $deuterium_bonus_percent,
                RESOURCE_ENERGY => $basicIncome[RESOURCE_ENERGY] * 0,
            ];
        }
        $fusion_planet_deut = 0;
        if (isset($productionList[fusion_plant]['production'][RESOURCE_DEUT])) {
            $fusion_planet_deut = $productionList[fusion_plant]['production'][RESOURCE_DEUT];
        }
        if ($positionBasedRessourceBonus) {
            $bonusProduction = [
                RESOURCE_METAL => ($temp[RESOURCE_METAL]['plus'] + $bonusProductionPositionBased[RESOURCE_METAL]) * (0.02 * $this->state->USER[$resource[METAL_PROC_TECH]]),
                RESOURCE_CRYSTAL => ($temp[RESOURCE_CRYSTAL]['plus'] + $bonusProductionPositionBased[RESOURCE_CRYSTAL]) * (0.02 * $this->state->USER[$resource[CRYSTAL_PROC_TECH]]),
                RESOURCE_DEUT => ($temp[RESOURCE_DEUT]['plus'] + $bonusProductionPositionBased[RESOURCE_DEUT]) * (0.02 * $this->state->USER[$resource[DEUTERIUM_PROC_TECH]]),
                RESOURCE_ENERGY => $temp[RESOURCE_ENERGY]['bonus'],
            ];

            $totalProduction = [
                RESOURCE_METAL => $temp[RESOURCE_METAL]['plus'] + $bonusProduction[RESOURCE_METAL] + $bonusProductionPositionBased[RESOURCE_METAL] + $basicProduction[RESOURCE_METAL],
                RESOURCE_CRYSTAL => $temp[RESOURCE_CRYSTAL]['plus'] + $bonusProduction[RESOURCE_CRYSTAL] + $bonusProductionPositionBased[RESOURCE_CRYSTAL] + $basicProduction[RESOURCE_CRYSTAL],
                RESOURCE_DEUT => $temp[RESOURCE_DEUT]['plus'] + $bonusProduction[RESOURCE_DEUT] + $bonusProductionPositionBased[RESOURCE_DEUT] + $basicProduction[RESOURCE_DEUT] + $fusion_planet_deut,
                RESOURCE_ENERGY => $this->state->PLANET[$resource[RESOURCE_ENERGY]] + $basicProduction[RESOURCE_ENERGY] + $this->state->PLANET[$resource[RESOURCE_ENERGY] . '_used'],
            ];
        } else {

            $bonusProduction = [
                RESOURCE_METAL => $temp[RESOURCE_METAL]['plus'] * (0.02 * $this->state->USER[$resource[METAL_PROC_TECH]]),
                RESOURCE_CRYSTAL => $temp[RESOURCE_CRYSTAL]['plus'] * (0.02 * $this->state->USER[$resource[CRYSTAL_PROC_TECH]]),
                RESOURCE_DEUT => $temp[RESOURCE_DEUT]['plus'] * (0.02 * $this->state->USER[$resource[DEUTERIUM_PROC_TECH]]),
                RESOURCE_ENERGY => $temp[RESOURCE_ENERGY]['bonus'],
            ];

            $totalProduction = [
                RESOURCE_METAL => $temp[RESOURCE_METAL]['plus'] + $bonusProduction[RESOURCE_METAL] + $basicProduction[RESOURCE_METAL],
                RESOURCE_CRYSTAL => $temp[RESOURCE_CRYSTAL]['plus'] + $bonusProduction[RESOURCE_CRYSTAL] + $basicProduction[RESOURCE_CRYSTAL],
                RESOURCE_DEUT => $temp[RESOURCE_DEUT]['plus'] + $bonusProduction[RESOURCE_DEUT] + $basicProduction[RESOURCE_DEUT] + $fusion_planet_deut,
                RESOURCE_ENERGY => $this->state->PLANET[$resource[RESOURCE_ENERGY]] + $basicProduction[RESOURCE_ENERGY] + $this->state->PLANET[$resource[RESOURCE_ENERGY] . '_used'],
            ];
        }

        $dailyProduction = [
            RESOURCE_METAL => $totalProduction[RESOURCE_METAL] * 24,
            RESOURCE_CRYSTAL => $totalProduction[RESOURCE_CRYSTAL] * 24,
            RESOURCE_DEUT => $totalProduction[RESOURCE_DEUT] * 24,
            RESOURCE_ENERGY => $totalProduction[RESOURCE_ENERGY],
        ];

        $weeklyProduction = [
            RESOURCE_METAL => $totalProduction[RESOURCE_METAL] * 168,
            RESOURCE_CRYSTAL => $totalProduction[RESOURCE_CRYSTAL] * 168,
            RESOURCE_DEUT => $totalProduction[RESOURCE_DEUT] * 168,
            RESOURCE_ENERGY => $totalProduction[RESOURCE_ENERGY],
        ];

        if (isset($productionList[fusion_plant]['production'][RESOURCE_ENERGY])) {
            $productionList[fusion_plant]['production'][RESOURCE_ENERGY] = $productionList[fusion_plant]['production'][RESOURCE_ENERGY] - $bonusProduction[RESOURCE_ENERGY];
        }

        $this->assign([
            'header'                            => $LNG->format('rs_production_on_planet', $this->state->PLANET['name']),
            'prodSelector'                      => $prodSelector,
            'productionList'                    => $productionList,
            'basicProduction'                   => $basicProduction,
            'totalProduction'                   => $totalProduction,
            'bonusProduction'                   => $bonusProduction,
            'positionBasedRessourceBonus'       => $positionBasedRessourceBonus,
            'bonusProductionPositionBased'      => $bonusProductionPositionBased,
            'dailyProduction'                   => $dailyProduction,
            'weeklyProduction'                  => $weeklyProduction,
            'storage'                           => $storage,
            'metalProcTech'                     => $this->state->USER[$resource[METAL_PROC_TECH]],
            'crystalProcTech'                   => $this->state->USER[$resource[CRYSTAL_PROC_TECH]],
            'deuteriumProcTech'                 => $this->state->USER[$resource[DEUTERIUM_PROC_TECH]],
            'metalBonusPercent'                 => $metal_bonus_percent * 100,
            'crystalBonusPercent'               => $crystal_bonus_percent * 100,
            'deuteriumBonusPercent'             => $deuterium_bonus_percent * 100,
        ]);

        return $this->render('game/page.resources.default.html.twig');
    }
}
