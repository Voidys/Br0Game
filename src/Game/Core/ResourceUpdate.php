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

use App\Game\Achievements\PlanetRessAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

class ResourceUpdate
{
    use \App\Game\Core\GameUtilities;

    /**
     * reference of the config object
     * @var Config
     */
    private $config = null;

    private $TIME = null;
    private $HASH = null;

    private $Build = true;
    private $Tech = true;

    private $PLANET = [];
    private $USER = [];
    private $Builded = [];
    private $Repaired = [];
    /** @var array{0: int, 1: array|false}|null wreckfield row read by RepairJob() for WreckfieldCheck() */
    private $repairJobWreckfield = null;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        $Build = true, $Tech = true,
    ) {
        $this->Build = $Build;
        $this->Tech = $Tech;

        $this->Builded[RESOURCE_METAL] = 0.0;
        $this->Builded[RESOURCE_CRYSTAL] = 0.0;
        $this->Builded[RESOURCE_DEUT] = 0.0;
    }

    public function setData($USER, $PLANET)
    {
        $this->USER = $USER;
        $this->PLANET = $PLANET;
    }

    public function getData()
    {
        return [$this->USER, $this->PLANET];
    }

    public function ReturnVars()
    {
        return [$this->USER, $this->PLANET];
    }

    public function CreateHash()
    {
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;
        $Hash = [];
        foreach ($reslist['prod'] as $ID) {
            $Hash[] = $this->PLANET[$resource[$ID]];
            $Hash[] = $this->PLANET[$resource[$ID] . '_porcent'];
        }

        $ressource = array_merge([], $reslist['resstype'][1], $reslist['resstype'][2]);
        foreach ($ressource as $ID) {
            $Hash[] = $this->config->{$resource[$ID] . '_basic_income'};
        }

        $Hash[] = $this->config->resource_multiplier;
        $Hash[] = $this->config->storage_multiplier;
        $Hash[] = $this->config->energy_multiplier;
        $Hash[] = $this->PLANET[$resource[22]];
        $Hash[] = $this->PLANET[$resource[23]];
        $Hash[] = $this->PLANET[$resource[24]];
        $Hash[] = $this->USER[$resource[113]];
        $Hash[] = $this->USER[$resource[131]];
        $Hash[] = $this->USER[$resource[132]];
        $Hash[] = $this->USER[$resource[133]];
        return md5(implode("::", $Hash));
    }

    public function CalcResource($USER, $PLANET, $SAVE = false, $TIME = null, $HASH = true)
    {
        $this->USER = $USER;
        $this->PLANET = $PLANET;
        $this->TIME = is_null($TIME) ? $this->state->runtime->timestamp : $TIME;
        $this->config = $this->configs->get($this->USER['universe']);

        if ($this->USER['urlaubs_modus'] == 1 
            || $this->config->uni_status == STATUS_CLOSED 
            || $this->config->uni_status == STATUS_REG_ONLY
            || $this->PLANET['destruyed'] != 0) {
            return $this->ReturnVars();
        }

        $ProductionTime = ($this->TIME - $this->PLANET['last_update']);

        if ($this->Build && $ProductionTime > 0) {
            $this->ShipyardQueue();
            $this->RepairJob();
            $this->WreckfieldCheck($this->PLANET['id'], $this->TIME); // Option: Delete not in Umode

            while (($this->Tech && $this->USER['b_tech'] != 0 && $this->USER['b_tech'] < $this->TIME) ||
                    ($this->PLANET['b_building'] != 0 && $this->PLANET['b_building'] < $this->TIME)) {
                if ($this->PLANET['b_building'] != 0 && (!$this->Tech || $this->USER['b_tech'] == 0 || $this->PLANET['b_building'] <= $this->USER['b_tech'])) {
                    if ($this->CheckPlanetBuildingQueue()) {
                        $this->SetNextQueueElementOnTop();
                    }
                } elseif ($this->Tech && $this->USER['b_tech'] != null) {
                    if ($this->CheckUserTechQueue()) {
                        $this->SetNextQueueTechOnTop();
                    }
                }
            }

            $this->UpdateResource($this->TIME, $HASH);

            if ($SAVE === true) {
                $this->SavePlanetToDB($this->USER, $this->PLANET);
            }
        }

        return $this->ReturnVars();
    }

    private function UpdateResource($TIME, $HASH = false)
    {
        $ProductionTime = ($TIME - $this->PLANET['last_update']);

        if ($ProductionTime > 0) {
            $this->PLANET['last_update'] = $TIME;
            if ($HASH === false) {
                $this->ReBuildCache();
            } else {
                $this->HASH = $this->CreateHash();

                if ($this->PLANET['eco_hash'] !== $this->HASH) {
                    $this->PLANET['eco_hash'] = $this->HASH;
                    $this->ReBuildCache();
                }
            }
            $this->ExecCalc($ProductionTime);
        }
    }

    private function ExecCalc($ProductionTime)
    {
        if ($this->PLANET['planet_type'] == 3) {
            return;
        }

        $MaxMetalStorage = $this->PLANET['metal_max'] * $this->config->max_overflow;
        $MaxCristalStorage = $this->PLANET['crystal_max'] * $this->config->max_overflow;
        $MaxDeuteriumStorage = $this->PLANET['deuterium_max'] * $this->config->max_overflow;

        $MetalTheoretical = $ProductionTime * (
            ($this->config->metal_basic_income * ($this->config->resource_multiplier / 2500))
                + $this->PLANET['metal_perhour']
        ) / 3600;

        $this->setProduction(RESOURCE_METAL, $MetalTheoretical, $MaxMetalStorage);

        $CristalTheoretical = $ProductionTime * (
            ($this->config->crystal_basic_income * ($this->config->resource_multiplier / 2500))
                + $this->PLANET['crystal_perhour']
        ) / 3600;

        $this->setProduction(RESOURCE_CRYSTAL, $CristalTheoretical, $MaxCristalStorage);

        $DeuteriumTheoretical = $ProductionTime * (
            ($this->config->deuterium_basic_income * ($this->config->resource_multiplier / 2500))
                + $this->PLANET['deuterium_perhour']
        ) / 3600;

        $this->setProduction(RESOURCE_DEUT, $DeuteriumTheoretical, $MaxDeuteriumStorage);
    }

    private function setProduction($resource_type, $produced, $storage)
    {
        $resource = & $this->state->resource;

        if ($produced < 0) {
            if ($this->PLANET[$resource[$resource_type]] + $produced < 0) {
                // consumption larger than the stock empties it exactly (was 1 + the fraction left over)
                $produced = -$this->PLANET[$resource[$resource_type]];
            }
        } elseif ($this->PLANET[$resource[$resource_type]] <= $storage) {
            if ($this->PLANET[$resource[$resource_type]] + $produced > $storage) {
                $produced = $storage - $this->PLANET[$resource[$resource_type]];
            }
        } else {
            $produced = 0;
        }

        $this->Builded[$resource_type] += $produced;
        $this->PLANET[$resource[$resource_type]] += $produced;
    }

    public function getProd($Calculation, $Element = false)
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $USER = & $this->state->USER;
        $PLANET = & $this->state->PLANET;
        if ($Element) {
            $BuildEnergy = $USER[$resource[113]];
            $BuildTemp = $PLANET['temp_max'];
            $BuildLevelFactor = $PLANET[$resource[$Element] . '_porcent'];

            if (in_array($Element, array_merge($reslist['build'], $reslist['fleet'], $reslist['defense']))) {
                $BuildLevel = $PLANET[$resource[$Element]];
            } elseif (in_array($Element, $reslist['tech'])) {
                $BuildLevel = $USER[$resource[$Element]];
            } else {
                $BuildLevel = 0;
            }

            $Calculation = str_replace('this->', "", $Calculation);
        }

        return 'return ' . $Calculation . ';';
    }

    public function getNetworkLevel($USER, $PLANET)
    {
        $resource = & $this->state->resource;

        $researchLevelList = [$PLANET[$resource[31]]];
        if ($USER[$resource[123]] > 0) {
            $sql = 'SELECT ' . $resource[31]
                    . ' FROM %%PLANETS%% WHERE id != :planetId AND id_owner = :userId AND destruyed = 0 ORDER BY '
                    . $resource[31] . ' DESC LIMIT :limit;';

            $researchResult = $this->db->select($sql, [
                ':limit'    => (int) $USER[$resource[123]],
                ':planetId' => $PLANET['id'],
                ':userId'   => $USER['id']
            ]);

            foreach ($researchResult as $researchRow) {
                $researchLevelList[] = $researchRow[$resource[31]];
            }
        }

        return $researchLevelList;
    }

    /** @var array<string, \Closure> compiled production/storage formulas by formula text */
    private static array $formulaCache = [];

    /**
     * Evaluates a production/storage formula of the vars table. The formula is compiled once per process
     * (eval parses on every call) into a closure over the variables the formulas may use.
     */
    private function runFormula(string $formula, $BuildLevel, $BuildTemp, $BuildEnergy, $BuildLevelFactor)
    {
        $fn = self::$formulaCache[$formula] ??= eval(
            'return function ($BuildLevel, $BuildTemp, $BuildEnergy, $BuildLevelFactor) { '
            . $this->getProd($formula) . ' };'
        );

        return $fn($BuildLevel, $BuildTemp, $BuildEnergy, $BuildLevelFactor);
    }

    public function ReBuildCache()
    {
        $ProdGrid = & $this->state->ProdGrid;
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        // Moons get no basic income: ExecCalc() returns early for them, the shared config stays untouched.

        $planetPosistionBasedRessBoost = true;

        $temp = [
            901 => [
                'max'   => 0,
                'plus'  => 0,
                'minus' => 0,
            ],
            902 => [
                'max'   => 0,
                'plus'  => 0,
                'minus' => 0,
            ],
            903 => [
                'max'   => 0,
                'plus'  => 0,
                'minus' => 0,
            ],
            911 => [
                'plus'  => 0,
                'minus' => 0,
            ]
        ];

        /* Data for eval */
        $BuildTemp = $this->PLANET['temp_max'];
        $BuildEnergy = $this->USER[$resource[113]];

        foreach ($reslist['storage'] as $ProdID) {
            foreach ($reslist['resstype'][1] as $ID) {
                if (!isset($ProdGrid[$ProdID]['storage'][$ID])) {
                    continue;
                }

                /* Data for eval */
                $BuildLevel = $this->PLANET[$resource[$ProdID]];
                $temp[$ID]['max']   += round($this->runFormula(
                    $ProdGrid[$ProdID]['storage'][$ID],
                    $BuildLevel,
                    $BuildTemp,
                    $BuildEnergy,
                    null
                ));
            }
        }

        $ressIDs = array_merge([], $reslist['resstype'][1], $reslist['resstype'][2]);

        foreach ($reslist['prod'] as $ProdID) {
            /* Data for eval */
            $BuildLevelFactor = $this->PLANET[$resource[$ProdID] . '_porcent'];
            $BuildLevel = $this->PLANET[$resource[$ProdID]];

            foreach ($ressIDs as $ID) {
                if (!isset($ProdGrid[$ProdID]['production'][$ID])) {
                    continue;
                }

                $Production = $this->runFormula(
                    $ProdGrid[$ProdID]['production'][$ID],
                    $BuildLevel,
                    $BuildTemp,
                    $BuildEnergy,
                    $BuildLevelFactor
                );

                if ($Production > 0) {
                    $temp[$ID]['plus']  += $Production;
                } else {
                    // Consumption (fusion plant deuterium) is always counted, even at a stock of 0: the stock is not part
                    // of the eco hash, so skipping it here froze a wrong production until the next hash change. A
                    // negative net production simply keeps the stock at 0 (see setProduction).
                    $temp[$ID]['minus'] += $Production;
                }
            }
        }

        $this->PLANET['metal_max'] = $temp[901]['max'] * $this->config->storage_multiplier;
        $this->PLANET['crystal_max'] = $temp[902]['max'] * $this->config->storage_multiplier;
        $this->PLANET['deuterium_max'] = $temp[903]['max'] * $this->config->storage_multiplier;

        $this->PLANET['energy'] = round($temp[911]['plus'] * $this->config->energy_multiplier);
        $this->PLANET['energy_used'] = $temp[911]['minus'] * $this->config->energy_multiplier;
        // Without energy consumers (all mines off) there is nothing to throttle, but the fusion plant still burns its
        // deuterium (like OGame, the reactor runs at its set level regardless of consumers), so production is not zeroed.
        $energyUsed = abs($this->PLANET['energy_used']);
        $prodLevel = $energyUsed > 0 ? min(1, $this->PLANET['energy'] / $energyUsed) : 1;

        // hier manipulieren für ress produktion
        // $temp[901]['plus']                           -> regulärer zuwachs an ressource
        // (1 + 0.02 * $this->USER[$resource[131]])     -> maximierer
        // $prodLevel                                   -> Energiefaktor bei defizit
        // $temp[901]['minus']                          -> Abzug / verbrauch von deut
        // $this->config->resource_multiplier           -> öko faktor


        $metal_bonus_percent     = 1;
        $crystal_bonus_percent   = 1;
        $deuterium_bonus_percent = 1;
        if ($this->config->planet_ressource_bonus) {
            $metal_bonus_percent     = 1 + $this->PLANET['metal_bonus_percent'] / 100;
            $crystal_bonus_percent   = 1 + $this->PLANET['crystal_bonus_percent'] / 100;
            $deuterium_bonus_percent = 1 + $this->PLANET['deuterium_bonus_percent'] / 100;
        }

        $this->PLANET['metal_perhour'] = (
            ($temp[901]['plus'] * $metal_bonus_percent) * (1 + 0.02 * $this->USER[$resource[131]]) * $prodLevel + $temp[901]['minus']
        ) * ($this->config->resource_multiplier / 2500);

        $this->PLANET['crystal_perhour'] = (
            ($temp[902]['plus'] * $crystal_bonus_percent) * (1 + 0.02 * $this->USER[$resource[132]]) * $prodLevel + $temp[902]['minus']
        ) * ($this->config->resource_multiplier / 2500);

        $this->PLANET['deuterium_perhour'] = (
            ($temp[903]['plus'] * $deuterium_bonus_percent) * (1 + 0.02 * $this->USER[$resource[133]]) * $prodLevel + $temp[903]['minus']
        ) * ($this->config->resource_multiplier / 2500);
    }

    /**
     * Checks wreckfield for deletion after 72h. Will be called in PlanetRessUpdate and GalxyRows.
     *
     * @param int $planetID
     * @param int $time
     * @param boolean $vmode
     * @return boolean true, when wreckfield was deleted
     */
    private function WreckfieldCheck($planetID, $time, $vmode = false)
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK) || $vmode) {
            return false;
        }
        $db = $this->db;

        if ($this->repairJobWreckfield !== null && $this->repairJobWreckfield[0] == $planetID) {
            // RepairJob() just read (and locked) this very row: no second query
            $wreckfield = $this->repairJobWreckfield[1];
        } else {
            $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID FOR UPDATE;";
            $wreckfield = $db->selectSingle($sql, [':planetID' => $planetID]);
        }
        $this->repairJobWreckfield = null;

        if (!empty($wreckfield) && $wreckfield['created'] <= $time - TIME_72_HOURS) {
            if (empty($wreckfield['repair_order'])) {
                $sql = "DELETE FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;";
                $db->delete($sql, [':planetID' => $planetID]);
            } else {
                $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `ships` = ''
                    WHERE `planetID` = :planetID;";
                $db->update($sql, [':planetID' => $planetID]);
            }
            return true;
        }
        return false;
    }

    /**
     * Automatic deployment of finished repair jobs after 72h.
     *
     * @return void
     */
    private function RepairJob()
    {
        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)) {
            return;
        }
        $resource = & $this->state->resource;
        $db = $this->db;

        $sql = "SELECT * FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID FOR UPDATE;";
        $wreckfield = $db->selectSingle($sql, [':planetID' => $this->PLANET['id']]);
        $this->repairJobWreckfield = [$this->PLANET['id'], $wreckfield];

        if (!empty($wreckfield['repair_order']) && $wreckfield['repair_order_end'] <= $this->TIME - TIME_72_HOURS) {
            $ElementQueue = unserialize($wreckfield['repair_order']);

            foreach ($ElementQueue as $elementID => $amount) {
                if (!isset($this->Repaired[$elementID])) {
                    $this->Repaired[$elementID] = 0;
                }
                if (!isset($this->Builded[$elementID])) {
                    $this->Builded[$elementID] = 0;
                }

                $this->Repaired[$elementID]           += $amount;
                $this->Builded[$elementID]            += $amount;
                $this->PLANET[$resource[$elementID]]  += $amount;
            }

            $sql = "UPDATE %%PLANET_WRECKFIELD%% SET `repair_order` = '', `repair_order_start` = 0, `repair_order_end` = 0
                WHERE `planetID` = :planetID;";
            $db->update($sql, [':planetID' => $this->PLANET['id']]);

            $this->repairJobWreckfield[1]['repair_order'] = '';
            $this->repairJobWreckfield[1]['repair_order_start'] = 0;
            $this->repairJobWreckfield[1]['repair_order_end'] = 0;
        }
    }

    private function ShipyardQueue()
    {
        $resource = & $this->state->resource;

        $BuildQueue = !empty($this->PLANET['b_hangar_id']) ? unserialize($this->PLANET['b_hangar_id']) : [];
        if (!$BuildQueue) {
            $this->PLANET['b_hangar'] = 0;
            $this->PLANET['b_hangar_id'] = '';
            return false;
        }

        $this->PLANET['b_hangar']   += ($this->TIME - $this->PLANET['last_update']);
        $BuildArray = [];
        foreach ($BuildQueue as $Item) {
            $AcumTime = $this->buildFunctions()->getBuildingTime($this->USER, $this->PLANET, $Item[0]);
            $BuildArray[] = [$Item[0], $Item[1], $AcumTime];
        }

        $NewQueue = [];
        $Done = false;
        foreach ($BuildArray as $Item) {
            $Element = $Item[0];
            $Count = $Item[1];

            if ($Done == false) {
                $BuildTime = $Item[2];
                $Element = (int)$Element;
                if ($BuildTime == 0) {
                    if (!isset($this->Builded[$Element])) {
                        $this->Builded[$Element] = 0;
                    }

                    $this->Builded[$Element]            += $Count;
                    $this->PLANET[$resource[$Element]]  += $Count;
                    continue;
                }

                $Build = max(min(floor($this->PLANET['b_hangar'] / $BuildTime), $Count), 0);

                if ($Build == 0) {
                    $NewQueue[] = [$Element, $Count];
                    $Done = true;
                    continue;
                }

                if (!isset($this->Builded[$Element])) {
                    $this->Builded[$Element] = 0;
                }

                $this->Builded[$Element]            += $Build;
                $this->PLANET['b_hangar']           -= $Build * $BuildTime;
                $this->PLANET[$resource[$Element]]  += $Build;
                $Count                              -= $Build;

                if ($Count == 0) {
                    continue;
                } else {
                    $Done = true;
                }
            }
            $NewQueue[] = [$Element, $Count];
        }

        if (empty($NewQueue)) {
            $this->PLANET['b_hangar_id'] = '';
            $this->PLANET['b_hangar'] = 0;
        } else {
            $this->PLANET['b_hangar_id'] = serialize($NewQueue);
        }

        return true;
    }

    private function CheckPlanetBuildingQueue()
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        if (empty($this->PLANET['b_building_id']) || empty($this->PLANET['b_building_id']) || $this->PLANET['b_building'] > $this->TIME) {
            return false;
        }

        $CurrentQueue = unserialize($this->PLANET['b_building_id']);

        $Element = $CurrentQueue[0][0];
        $BuildLevel = $CurrentQueue[0][1];
        $BuildEndTime = $CurrentQueue[0][3];
        $BuildMode = $CurrentQueue[0][4];

        if (!isset($this->Builded[$Element])) {
            $this->Builded[$Element] = 0;
        }

        $OnHash = in_array($Element, $reslist['prod']);
        $this->UpdateResource($BuildEndTime, !$OnHash);

        if ($BuildMode == 'build') {
            $this->PLANET['field_current']      += 1;
            $this->PLANET[$resource[$Element]]  = $BuildLevel;
            $this->Builded[$Element]            = $BuildLevel;
        } else {
            $this->PLANET['field_current']      -= 1;
            $this->PLANET[$resource[$Element]]  = $BuildLevel - 1;
            $this->Builded[$Element]            = $BuildLevel - 1;
        }

        array_shift($CurrentQueue);

        if (count($CurrentQueue) == 0) {
            $this->PLANET['b_building'] = 0;
            $this->PLANET['b_building_id'] = '';

            return false;
        } else {
            $this->PLANET['b_building_id'] = serialize($CurrentQueue);
            return true;
        }
    }

    public function SetNextQueueElementOnTop()
    {
        $resource = & $this->state->resource;
        $LNG = $this->playerUtil()->getLanguage(null, $this->USER['id']);
        if (empty($LNG)) {
            // Fallback language
            $LNG = new Language($this->configs, 'en');
            $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        }

        if (empty($this->PLANET['b_building_id'])) {
            $this->PLANET['b_building'] = 0;
            $this->PLANET['b_building_id'] = '';
            return false;
        }

        $CurrentQueue = !empty($this->PLANET['b_building_id']) ? unserialize($this->PLANET['b_building_id']) : [];
        $Loop = true;

        $BuildEndTime = 0;
        $NewQueue = '';

        while ($Loop === true) {
            $ListIDArray = $CurrentQueue[0];
            $Element = $ListIDArray[0];
            $Level = $ListIDArray[1];
            $BuildMode = $ListIDArray[4];
            $ForDestroy = ($BuildMode == 'destroy') ? true : false;
            $costResources = $this->buildFunctions()->getElementPrice($this->USER, $this->PLANET, $Element, $ForDestroy, $Level);
            $BuildTime = $this->buildFunctions()->getBuildingTime($this->USER, $this->PLANET, $Element, $costResources, $ForDestroy);
            $HaveResources = $this->buildFunctions()->isElementBuyable($this->USER, $this->PLANET, $Element, $costResources);
            // Only the job that is about to start occupies a field now. Later queue
            // entries are still pending, and earlier ones are already on $this->PLANET.
            $HaveFields = $ForDestroy || $this->buildFunctions()->getQueueFieldProjection($this->PLANET, [$ListIDArray])['valid'];
            $BuildEndTime = $this->PLANET['b_building'] + $BuildTime;
            $CurrentQueue[0] = [$Element, $Level, $BuildTime, $BuildEndTime, $BuildMode, $costResources];
            $HaveNoMoreLevel = false;

            if ($ForDestroy && $this->PLANET[$resource[$Element]] == 0) {
                $HaveResources = false;
                $HaveNoMoreLevel = true;
            }

            if ($HaveResources === true && $HaveFields === true) {
                if (isset($costResources[RESOURCE_METAL])) {
                    $this->Builded[RESOURCE_METAL] -= $costResources[RESOURCE_METAL];
                    $this->PLANET[$resource[RESOURCE_METAL]] -= $costResources[RESOURCE_METAL];
                }
                if (isset($costResources[RESOURCE_CRYSTAL])) {
                    $this->Builded[RESOURCE_CRYSTAL] -= $costResources[RESOURCE_CRYSTAL];
                    $this->PLANET[$resource[RESOURCE_CRYSTAL]] -= $costResources[RESOURCE_CRYSTAL];
                }
                if (isset($costResources[RESOURCE_DEUT])) {
                    $this->Builded[RESOURCE_DEUT] -= $costResources[RESOURCE_DEUT];
                    $this->PLANET[$resource[RESOURCE_DEUT]] -= $costResources[RESOURCE_DEUT];
                }
                $NewQueue = serialize($CurrentQueue);
                $Loop = false;
            } else {
                if ($this->USER['hof'] == 1) {
                    if ($HaveNoMoreLevel) {
                        $Message = sprintf($LNG['sys_nomore_level'], $LNG['tech'][$Element]);
                    } elseif ($HaveResources !== true) {
                        if (!isset($costResources[RESOURCE_METAL])) {
                            $costResources[RESOURCE_METAL] = 0;
                        }
                        if (!isset($costResources[RESOURCE_CRYSTAL])) {
                            $costResources[RESOURCE_CRYSTAL] = 0;
                        }
                        if (!isset($costResources[RESOURCE_DEUT])) {
                            $costResources[RESOURCE_DEUT] = 0;
                        }

                        $Message = sprintf(
                            $LNG['sys_notenough_money'],
                            $this->PLANET['name'],
                            $this->PLANET['id'],
                            $this->PLANET['galaxy'],
                            $this->PLANET['system'],
                            $this->PLANET['planet'],
                            $LNG['tech'][$Element],
                            $this->viewHelpers()->prettyNumber($this->PLANET['metal']),
                            $LNG['tech'][RESOURCE_METAL],
                            $this->viewHelpers()->prettyNumber($this->PLANET['crystal']),
                            $LNG['tech'][RESOURCE_CRYSTAL],
                            $this->viewHelpers()->prettyNumber($this->PLANET['deuterium']),
                            $LNG['tech'][RESOURCE_DEUT],
                            $this->viewHelpers()->prettyNumber($costResources[RESOURCE_METAL]),
                            $LNG['tech'][RESOURCE_METAL],
                            $this->viewHelpers()->prettyNumber($costResources[RESOURCE_CRYSTAL]),
                            $LNG['tech'][RESOURCE_CRYSTAL],
                            $this->viewHelpers()->prettyNumber($costResources[RESOURCE_DEUT]),
                            $LNG['tech'][RESOURCE_DEUT]
                        );
                    } else {
                        $Message = sprintf(
                            '%s <a href="./game?page=buildings&amp;cp=%d&amp;re=0">[%d:%d:%d]</a>: %s (%s)',
                            $this->PLANET['name'],
                            $this->PLANET['id'],
                            $this->PLANET['galaxy'],
                            $this->PLANET['system'],
                            $this->PLANET['planet'],
                            $LNG['bd_no_more_fields'],
                            $LNG['tech'][$Element]
                        );
                    }

                    $this->playerUtil()->sendMessage(
                        $this->USER['id'],
                        0,
                        $LNG['sys_buildlist'],
                        MESSAGE_TYPE_BUILD_LIST,
                        $LNG['sys_buildlist_fail'],
                        $Message,
                        $this->TIME
                    );
                }

                array_shift($CurrentQueue);

                if (count($CurrentQueue) == 0) {
                    $BuildEndTime = 0;
                    $NewQueue = '';
                    $Loop = false;
                } else {
                    $BaseTime = $BuildEndTime - $BuildTime;
                    $NewQueue = [];
                    foreach ($CurrentQueue as $ID => $ListIDArray) {
                        $ListIDArray[2] = $this->buildFunctions()->getBuildingTime(
                            $this->USER,
                            $this->PLANET,
                            $ListIDArray[0],
                            null,
                            $ListIDArray[4] == 'destroy',
                            $ListIDArray[1]
                        );
                        $BaseTime += $ListIDArray[2];
                        $ListIDArray[3] = $BaseTime;
                        $NewQueue[] = $ListIDArray;
                    }
                    $CurrentQueue = $NewQueue;
                }
            }
        }

        $this->PLANET['b_building'] = $BuildEndTime;
        $this->PLANET['b_building_id'] = $NewQueue;

        return true;
    }

    private function CheckUserTechQueue()
    {
        $resource = & $this->state->resource;

        if (empty($this->USER['b_tech_id']) || $this->USER['b_tech'] > $this->TIME) {
            return false;
        }

        if (!isset($this->Builded[$this->USER['b_tech_id']])) {
            $this->Builded[$this->USER['b_tech_id']] = 0;
        }

        $CurrentQueue = !empty($this->USER['b_tech_queue']) ? unserialize($this->USER['b_tech_queue']) : [];

        $Element = $this->USER['b_tech_id'];
        $BuildLevel = $CurrentQueue[0][1];
        $BuildEndTime = $this->USER['b_tech'];

        $hashTechs = [113, 131, 132, 133];
        if (in_array($Element, $hashTechs)) {
            $sql = 'SELECT * FROM %%PLANETS%% WHERE id_owner = :userId AND id <> :planetId FOR UPDATE;';
            $PLANETS = $this->db->select($sql, [
                ':userId' => $this->USER['id'],
                ':planetId' => $this->PLANET['id'],
            ]);
            foreach ($PLANETS as $planetId => $cplanet) {
                $ressUpdate = new ResourceUpdate($this->db, $this->configs, $this->state, true, false);
                $ressUpdate->CalcResource($this->USER, $cplanet, true, $BuildEndTime, false);
            }
            $this->UpdateResource($BuildEndTime, false);
        } elseif (count($CurrentQueue) > 1) {
            // Bring the planet up to the end time so the next queued tech is paid from current resources
            $this->UpdateResource($BuildEndTime, false);
        }

        $this->Builded[$Element] = $BuildLevel;
        $this->USER[$resource[$Element]] = $BuildLevel;

        array_shift($CurrentQueue);

        if (count($CurrentQueue) == 0) {
            $this->USER['b_tech'] = 0;
            $this->USER['b_tech_id'] = 0;
            $this->USER['b_tech_planet'] = 0;
            $this->USER['b_tech_queue'] = '';
            return false;
        } else {
            $this->USER['b_tech_id'] = 0;
            $this->USER['b_tech_queue'] = serialize(array_values($CurrentQueue));
            return true;
        }
    }

    public function SetNextQueueTechOnTop($save = false)
    {
        $resource = & $this->state->resource;
        $LNG = $this->playerUtil()->getLanguage(null, $this->USER['id']);
        if (empty($LNG)) {
            // Fallback language
            $LNG = new Language($this->configs, 'en');
            $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        }
        if (empty($this->USER['b_tech_queue'])) {
            $this->USER['b_tech'] = 0;
            $this->USER['b_tech_id'] = 0;
            $this->USER['b_tech_planet'] = 0;
            $this->USER['b_tech_queue'] = '';
            return false;
        }

        $CurrentQueue = !empty($this->USER['b_tech_queue']) ? unserialize($this->USER['b_tech_queue']) : [];
        $Loop = true;
        while ($Loop == true) {
            $ListIDArray = $CurrentQueue[0];
            $isAnotherPlanet = $ListIDArray[4] != $this->PLANET['id'];
            if ($isAnotherPlanet) {
                $sql = 'SELECT * FROM %%PLANETS%% WHERE id = :planetId FOR UPDATE;';
                $PLANET = $this->db->selectSingle($sql, [
                    ':planetId' => $ListIDArray[4],
                ]);

                $RPLANET = new ResourceUpdate($this->db, $this->configs, $this->state, true, false);
                list(, $PLANET) = $RPLANET->CalcResource($this->USER, $PLANET, true, $this->USER['b_tech']);
            } else {
                $PLANET = $this->PLANET;
            }

            $PLANET[$resource[31] . '_inter'] = $this->getNetworkLevel($this->USER, $PLANET);

            $Element = $ListIDArray[0];
            $Level = $ListIDArray[1];
            $costResources = $this->buildFunctions()->getElementPrice($this->USER, $PLANET, $Element, false, $Level);
            $BuildTime = $this->buildFunctions()->getBuildingTime($this->USER, $PLANET, $Element, $costResources);
            $HaveResources = $this->buildFunctions()->isElementBuyable($this->USER, $PLANET, $Element, $costResources);
            $BuildEndTime = $this->USER['b_tech'] + $BuildTime;
            $CurrentQueue[0] = [$Element, $Level, $BuildTime, $BuildEndTime, $PLANET['id'], $costResources];

            if ($HaveResources == true) {
                if (isset($costResources[RESOURCE_METAL])) {
                    if (!$isAnotherPlanet) {
                        $this->Builded[RESOURCE_METAL] -= $costResources[RESOURCE_METAL];
                    }
                    $PLANET[$resource[RESOURCE_METAL]] -= $costResources[RESOURCE_METAL];
                }
                if (isset($costResources[RESOURCE_CRYSTAL])) {
                    if (!$isAnotherPlanet) {
                        $this->Builded[RESOURCE_CRYSTAL] -= $costResources[RESOURCE_CRYSTAL];
                    }
                    $PLANET[$resource[RESOURCE_CRYSTAL]] -= $costResources[RESOURCE_CRYSTAL];
                }
                if (isset($costResources[RESOURCE_DEUT])) {
                    if (!$isAnotherPlanet) {
                        $this->Builded[RESOURCE_DEUT] -= $costResources[RESOURCE_DEUT];
                    }
                    $PLANET[$resource[RESOURCE_DEUT]] -= $costResources[RESOURCE_DEUT];
                }

                if ($isAnotherPlanet) {
                    $this->removeResources($ListIDArray[4], $costResources);
                }

                $this->USER['b_tech_id'] = $Element;
                $this->USER['b_tech'] = $BuildEndTime;
                $this->USER['b_tech_planet'] = $PLANET['id'];
                $this->USER['b_tech_queue'] = serialize($CurrentQueue);

                $Loop = false;
            } else {
                if ($this->USER['hof'] == 1) {
                    if (!isset($costResources[RESOURCE_METAL])) {
                        $costResources[RESOURCE_METAL] = 0;
                    }
                    if (!isset($costResources[RESOURCE_CRYSTAL])) {
                        $costResources[RESOURCE_CRYSTAL] = 0;
                    }
                    if (!isset($costResources[RESOURCE_DEUT])) {
                        $costResources[RESOURCE_DEUT] = 0;
                    }

                    $Message = sprintf(
                        $LNG['sys_notenough_money'],
                        $PLANET['name'],
                        $PLANET['id'],
                        $PLANET['galaxy'],
                        $PLANET['system'],
                        $PLANET['planet'],
                        $LNG['tech'][$Element],
                        $this->viewHelpers()->prettyNumber($PLANET['metal']),
                        $LNG['tech'][RESOURCE_METAL],
                        $this->viewHelpers()->prettyNumber($PLANET['crystal']),
                        $LNG['tech'][RESOURCE_CRYSTAL],
                        $this->viewHelpers()->prettyNumber($PLANET['deuterium']),
                        $LNG['tech'][RESOURCE_DEUT],
                        $this->viewHelpers()->prettyNumber($costResources[RESOURCE_METAL]),
                        $LNG['tech'][RESOURCE_METAL],
                        $this->viewHelpers()->prettyNumber($costResources[RESOURCE_CRYSTAL]),
                        $LNG['tech'][RESOURCE_CRYSTAL],
                        $this->viewHelpers()->prettyNumber($costResources[RESOURCE_DEUT]),
                        $LNG['tech'][RESOURCE_DEUT]
                    );
                    $this->playerUtil()->sendMessage(
                        $this->USER['id'],
                        0,
                        $LNG['sys_techlist'],
                        MESSAGE_TYPE_BUILD_LIST,
                        $LNG['sys_buildlist_fail'],
                        $Message,
                        $this->TIME
                    );
                }

                array_shift($CurrentQueue);

                if (count($CurrentQueue) == 0) {
                    $this->USER['b_tech'] = 0;
                    $this->USER['b_tech_id'] = 0;
                    $this->USER['b_tech_planet'] = 0;
                    $this->USER['b_tech_queue'] = '';

                    $Loop = false;
                } else {
                    $BaseTime = $BuildEndTime - $BuildTime;
                    $NewQueue = [];
                    foreach ($CurrentQueue as $ListIDArray) {
                        $ListIDArray[2] = $this->buildFunctions()->getBuildingTime($this->USER, $PLANET, $ListIDArray[0], null, false, $ListIDArray[1]);
                        $BaseTime += $ListIDArray[2];
                        $ListIDArray[3] = $BaseTime;
                        $NewQueue[] = $ListIDArray;
                    }
                    $CurrentQueue = $NewQueue;
                }
            }

            if ($isAnotherPlanet) {
                $RPLANET = null;
                unset($RPLANET);
            } else {
                $this->PLANET = $PLANET;
            }
        }

        if ($save) {
            $this->SavePlanetToDB($this->USER, $this->PLANET);
        }

        return true;
    }

    private function SavePlanetToDB($USER, $PLANET)
    {
        $resource = & $this->state->resource;
        $reslist = & $this->state->reslist;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $buildQueries = [];
        $statsUpdateBuild = [];
        $statsUpdateRepaired = [];
        $statsUpdateFirst = [];

        $currentBuildings = 0;
        foreach ($reslist['build'] as $Element) {
            if (isset($PLANET[$resource[$Element]])) {
                $currentBuildings += $PLANET[$resource[$Element]];
            }
        }

        $params = [
            ':userId'               => $USER['id'],
            ':planetId'             => $PLANET['id'],
            ':ecoHash'              => $PLANET['eco_hash'],
            ':lastUpdateTime'       => $PLANET['last_update'],
            ':b_building'           => $PLANET['b_building'],
            ':b_building_id'        => $PLANET['b_building_id'],
            ':field_current'        => $currentBuildings > 0 ? $currentBuildings : $PLANET['field_current'],
            ':b_hangar_id'          => $PLANET['b_hangar_id'],
            ':metal_perhour'        => $PLANET['metal_perhour'],
            ':crystal_perhour'      => $PLANET['crystal_perhour'],
            ':deuterium_perhour'    => $PLANET['deuterium_perhour'],
            ':metal_max'            => $PLANET['metal_max'],
            ':crystal_max'          => $PLANET['crystal_max'],
            ':deuterium_max'        => $PLANET['deuterium_max'],
            ':energy_used'          => $PLANET['energy_used'],
            ':energy'               => $PLANET['energy'],
            ':b_hangar'             => $PLANET['b_hangar'],
            ':b_tech'               => $USER['b_tech'],
            ':b_tech_id'            => $USER['b_tech_id'],
            ':b_tech_planet'        => $USER['b_tech_planet'],
            ':b_tech_queue'         => $USER['b_tech_queue']
        ];

        if (!empty($this->Builded)) {
            foreach ($this->Builded as $Element => $Count) {
                $Element = (int) $Element;

                if (empty($resource[$Element])) {
                    continue;
                }

                if (in_array($Element, $reslist['one'])) {
                    $buildQueries[] = ', p.' . $resource[$Element] . ' = :' . $resource[$Element];
                    $params[':' . $resource[$Element]] = '1';
                    // Update First stats timestams
                    $statsUpdateFirst[] = [AdvancedStatsEnum::getFirst($Element), $this->TIME];
                    $statsUpdateBuild[] = [AdvancedStatsEnum::getBuild($Element), '1'];
                } elseif (isset($PLANET[$resource[$Element]])) {
                    if ($Element < 100) { // Set building level directly
                        $buildQueries[] = ', p.' . $resource[$Element] . ' = :' . $resource[$Element];
                        $params[':' . $resource[$Element]] = floatToString($Count);

                        if ($PLANET['planet_type'] == 3 && ($Element == 22 || $Element == 23 || $Element == 24) && $Count == 3) {
                            $temp_planet = $PLANET;
                            $temp_planet[$resource[$Element]] = $Count;
                            //  Check for achievement 28: Build all Moon storages to level 3
                            $this->planetRessAchievements()->checkPlanetRessAchievements28($temp_planet, $resource, $USER['id']);
                        }
                    } else {
                        $buildQueries[] = ', p.' . $resource[$Element] . ' = p.' . $resource[$Element] . ' + :' . $resource[$Element];
                        if ($Element >= 900 && $Element < 1000) {
                            $params[':' . $resource[$Element]] = floatToString($Count, 6);
                        } else {
                            $params[':' . $resource[$Element]] = floatToString($Count);
                        }
                    }
                    // Check for ship achievements
                    if ($Element >= 200 && $Element < 300) {
                        $this->planetRessAchievements()->checkPlanetRessAchievementsShips($Element, $USER['id']);
                    }
                    // Update Builded stats for ships, def and rockets
                    if ($Element >= 200 && $Element < 900) {
                        $toAdd = $Count;
                        if (array_key_exists($Element, $this->Repaired)) {
                            $toAdd -= $this->Repaired[$Element];
                            $statsUpdateRepaired[] = [AdvancedStatsEnum::getRepaired($Element), floatToString($this->Repaired[$Element])];
                        }
                        $statsUpdateBuild[] = [AdvancedStatsEnum::getBuild($Element), floatToString($toAdd)];
                    }
                    // Update First stats timestams
                    $statsUpdateFirst[] = [AdvancedStatsEnum::getFirst($Element), $this->TIME];
                } elseif (isset($USER[$resource[$Element]])) { // Set research level directly
                    $buildQueries[] = ', u.' . $resource[$Element] . ' = :' . $resource[$Element];
                    $params[':' . $resource[$Element]] = floatToString($Count);

                    // Check for research achievements
                    $this->planetRessAchievements()->checkPlanetRessAchievementsResearch($Element, $USER['id'], $Count);
                    // Update First stats timestams
                    $statsUpdateFirst[] = [AdvancedStatsEnum::getFirst($Element), $this->TIME];
                }
            }
        }

        $sql = 'UPDATE %%PLANETS%% as p, %%USERS%% as u SET
		p.eco_hash = :ecoHash,
		p.last_update = :lastUpdateTime,
		p.b_building = :b_building,
		p.b_building_id = :b_building_id,
		p.field_current = :field_current,
		p.b_hangar_id = :b_hangar_id,
		p.metal_perhour = :metal_perhour,
		p.crystal_perhour = :crystal_perhour,
		p.deuterium_perhour = :deuterium_perhour,
		p.metal_max = :metal_max,
		p.crystal_max = :crystal_max,
		p.deuterium_max = :deuterium_max,
		p.energy_used = :energy_used,
		p.energy = :energy,
		p.b_hangar = :b_hangar,
		u.b_tech = :b_tech,
		u.b_tech_id = :b_tech_id,
		u.b_tech_planet = :b_tech_planet,
		u.b_tech_queue = :b_tech_queue
		' . implode("\n", $buildQueries) . '
		WHERE p.id = :planetId AND u.id = :userId;';

        $this->db->update($sql, $params);

        if (!empty($statsUpdateBuild)) {
            foreach ($statsUpdateBuild as [$enum, $count]) {
                $statsHandler->increment($USER['id'], $enum, $count);
            }
        }
        if (!empty($statsUpdateRepaired)) {
            foreach ($statsUpdateRepaired as [$enum, $count]) {
                $statsHandler->increment($USER['id'], $enum, $count);
            }
        }
        if (!empty($statsUpdateFirst)) {
            foreach ($statsUpdateFirst as [$enum, $time]) {
                $statsHandler->setTimestamp($USER['id'], $enum, $time);
            }
        }

        $this->Builded = [
            RESOURCE_METAL => 0,
            RESOURCE_CRYSTAL => 0,
            RESOURCE_DEUT => 0,
        ];

        return [$USER, $PLANET];
    }

    public function removeResources(int $planetId, array $resources, ?array &$planet = null)
    {
        $resource =& $this->state->resource;
        
        $params = [':planetId' => $planetId];
        $planetQuery = [];
        foreach ($resources as $resourceId => $amount) {
            if ($resourceId == RESOURCE_ENERGY) {
                continue;
            }
            $planetQuery[] = $resource[$resourceId] . " = " . $resource[$resourceId] . " - :" . $resource[$resourceId];
            $params[':' . $resource[$resourceId]] = $amount;

            if (isset($planet, $planet[$resource[$resourceId]])) {
                $planet[$resource[$resourceId]] -= $amount;
            }
        }

        if (!empty($planetQuery)) {
            $sql = 'UPDATE %%PLANETS%% SET ' .
            implode(', ', $planetQuery) .
            ' WHERE id = :planetId;';
            $this->db->update($sql, $params);
        }
    }

    public function addResources(int $planetId, array $resources, ?array &$planet = null, float $factor = 1)
    {
        $resource =& $this->state->resource;

        $params = [':planetId' => $planetId];
        foreach ($resources as $resourceId => $amount) {
            if ($resourceId == RESOURCE_ENERGY) {
                continue;
            }
            $planetQuery[] = $resource[$resourceId] . " = " . $resource[$resourceId] . " + :" . $resource[$resourceId];
            $params[':' . $resource[$resourceId]] = $amount * $factor;

            if (isset($planet, $planet[$resource[$resourceId]])) {
                $planet[$resource[$resourceId]] += $amount * $factor;
            }
        }

        $sql = 'UPDATE %%PLANETS%% SET ' .
        implode(', ', $planetQuery) .
        ' WHERE id = :planetId;';
        $this->db->update($sql, $params);
    }

    public function saveResources(array $PLANET)
    {
        $params = [
            ':planetId'             => $PLANET['id'],
            ':metal'                => $PLANET['metal'],
            ':crystal'              => $PLANET['crystal'],
            ':deuterium'            => $PLANET['deuterium'],
        ];
        $sql = 'UPDATE %%PLANETS%% SET
		metal = :metal,
		crystal = :crystal,
		deuterium = :deuterium
		WHERE id = :planetId;';

        $this->db->update($sql, $params);
    }

    public function saveTechQueue(array $USER)
    {
        $params = [
            ':userId'        => $USER['id'],
            ':b_tech'        => $USER['b_tech'],
            ':b_tech_id'     => $USER['b_tech_id'],
            ':b_tech_planet' => $USER['b_tech_planet'],
            ':b_tech_queue'  => $USER['b_tech_queue']
        ];
        $sql = 'UPDATE %%USERS%% SET
		b_tech = :b_tech,
		b_tech_id = :b_tech_id,
		b_tech_planet = :b_tech_planet,
		b_tech_queue = :b_tech_queue
		WHERE id = :userId;';

        $this->db->update($sql, $params);
    }

    public function saveBuilingQueue(array $PLANET)
    {
        $params = [
            ':planetId'             => $PLANET['id'],
            ':b_building'           => $PLANET['b_building'],
            ':b_building_id'        => $PLANET['b_building_id'],
            ':watch_id'             => $PLANET['watch_id']
        ];
        $sql = 'UPDATE %%PLANETS%% SET
        b_building = :b_building,
		b_building_id = :b_building_id,
        watch_id = :watch_id
		WHERE id = :planetId;';

        $this->db->update($sql, $params);
    }

    public function saveShipyardQueue(array $PLANET)
    {
        $params = [
            ':planetId'             => $PLANET['id'],
            ':b_hangar_id'          => $PLANET['b_hangar_id'],
            ':b_hangar'             => $PLANET['b_hangar'],
        ];
        $sql = 'UPDATE %%PLANETS%% SET
        b_hangar_id = :b_hangar_id,
		b_hangar = :b_hangar
		WHERE id = :planetId;';

        $this->db->update($sql, $params);
    }
}
