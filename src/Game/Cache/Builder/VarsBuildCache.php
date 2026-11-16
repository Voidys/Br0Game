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

namespace App\Game\Cache\Builder;

use App\Game\Core\Database;
use App\Game\Core\Universe;

/**
 * Builds the "vars" cache: the game data tables (elements, costs, production, storage, combat values, rapid fire,
 * requirements) per universe, with universe 0 holding the defaults. The result feeds GameData, which exposes it as
 * the globals $resource, $reslist, $pricelist, $CombatCaps, $ProdGrid and $requeriments.
 */
class VarsBuildCache implements BuildCacheInterface
{
    public function __construct(
        private ?Database $db = null,
        private ?\App\Game\Core\Singleton $state = null,
    ) {}

    /**
     * Reads vars, vars_require and vars_rapidfire for universe 0 and every available universe and sorts the elements
     * into the lists of `reslist` by their class: 0 buildings (with `allow` per planet type 1/3), 100 research, 200 ships,
     * 400 defense, 500 missiles, plus prod/storage/one-per-planet lists and the ship types (civil/military/recycler).
     * @return array<int, array{reslist: array, ProdGrid: array, CombatCaps: array, resource: array, pricelist: array, requeriments: array}> by universe id.
     * @throws \LogicException without database and state.
     */
    public function buildCache()
    {
        if ($this->db === null || $this->state === null) {
            throw new \LogicException('The vars cache is built from the database: create the Cache with Database and game state.');
        }
        $db = $this->db;

        $availableUniverses = (new Universe($this->state))->availableUniverses();
        $unis = [];
        $unis[] = 0;
        foreach ($availableUniverses as $key => $value) {
            $unis[] = $value;
        }

        $result = [];
        foreach ($unis as $universe) {
            $result[$universe] = $this->buildUniverse($universe);
        }
        return $result;
    }

    /**
     * Cache data of one universe (0 = defaults): the requirements, the element table (names, combat values, prices,
     * production and storage grid, the element lists by class) and the rapidfire values.
     *
     * @return array{reslist: array, ProdGrid: array, CombatCaps: array, resource: array, pricelist: array, requeriments: array}
     */
    private function buildUniverse(int $universe): array
    {
        $db = $this->db;
        $resource = [];
        $requeriments = [];
        $pricelist = [];
        $CombatCaps = [];
        $reslist = [];
        $ProdGrid = [];

        $reslist['prod'] = [];
        $reslist['storage'] = [];
        $reslist['one'] = [];
        $reslist['build'] = [];
        $reslist['allow'][1] = [];
        $reslist['allow'][3] = [];
        $reslist['tech'] = [];
        $reslist['fleet'] = [];
        $reslist['defense'] = [];
        $reslist['missile'] = [];
        $reslist['ship_type']['unknown'] = [];
        $reslist['ship_type']['civil'] = [];
        $reslist['ship_type']['military'] = [];
        $reslist['ship_type']['recycler'] = [];

        $reqResult = $db->select('SELECT * FROM %%VARS_REQUIRE%% WHERE universe = :universe;', [':universe' => $universe]);
        foreach ($reqResult as $reqRow) {
            $requeriments[$reqRow['elementID']][$reqRow['requireID']] = $reqRow['requireLevel'];
        }

        $varsResult = $db->select('SELECT * FROM %%VARS%% WHERE universe = :universe;', [':universe' => $universe]);
        foreach ($varsResult as $varsRow) {
            $resource[$varsRow['elementID']] = $varsRow['name'];
            $CombatCaps[$varsRow['elementID']] = $this->combatCapsOf($varsRow);
            $pricelist[$varsRow['elementID']] = $this->pricelistOf($varsRow);

            $ProdGrid[$varsRow['elementID']]['production'] = [
                901 => $varsRow['production901'],
                902 => $varsRow['production902'],
                903 => $varsRow['production903'],
                911 => $varsRow['production911'],
            ];

            $ProdGrid[$varsRow['elementID']]['storage'] = [
                901 => $varsRow['storage901'],
                902 => $varsRow['storage902'],
                903 => $varsRow['storage903'],
            ];

            $this->sortIntoLists($reslist, $varsRow, $ProdGrid[$varsRow['elementID']]);
        }

        $rapidResult = $db->select('SELECT * FROM %%VARS_RAPIDFIRE%% WHERE universe = :universe;', [':universe' => $universe]);
        foreach ($rapidResult as $rapidRow) {
            $CombatCaps[$rapidRow['elementID']]['sd'][$rapidRow['rapidfireID']] = $rapidRow['shoots'];
        }

        return [
            'reslist'       => $reslist,
            'ProdGrid'      => $ProdGrid,
            'CombatCaps'    => $CombatCaps,
            'resource'      => $resource,
            'pricelist'     => $pricelist,
            'requeriments'  => $requeriments,
        ];
    }

    /**
     * Combat values of an element row (attack, shield, big shield, stun).
     *
     * @param array<string, mixed> $varsRow row of `vars`
     * @return array<string, mixed>
     */
    private function combatCapsOf(array $varsRow): array
    {
        return [
            'attack'    => $varsRow['attack'],
            'shield'    => $varsRow['defend'],
            'bigshield' => $varsRow['bigshield'],
            'stun'      => $varsRow['stun'],
        ];
    }

    /**
     * Price list entry of an element row: costs, cost factor, max level, fuel consumption, speeds and speed techs,
     * capacity and the max useful level.
     *
     * @param array<string, mixed> $varsRow row of `vars`
     * @return array<string, mixed>
     */
    private function pricelistOf(array $varsRow): array
    {
        return [
            'cost'      => [
                901 => $varsRow['cost901'],
                902 => $varsRow['cost902'],
                903 => $varsRow['cost903'],
                911 => $varsRow['cost911'],
            ],
            'factor'        => $varsRow['factor'],
            'max'           => $varsRow['maxLevel'],
            'consumption1'  => $varsRow['consumption1'],
            'consumption2'  => $varsRow['consumption2'],
            'consumption3'  => $varsRow['consumption3'],
            'tech1'         => $varsRow['speedTech1'],
            'tech2'         => $varsRow['speedTech2'],
            'tech3'         => $varsRow['speedTech3'],
            'speed1'        => $varsRow['speed1'],
            'speed2'        => $varsRow['speed2'],
            'speed3'		=> $varsRow['speed3'],
            'capacity'      => $varsRow['capacity'],
            'max_useful_level' => $varsRow['max_useful_level'],
        ];
    }

    /**
     * Adds an element to the lists of `reslist`: producers and storages (non-zero grid), one-per-planet, its ship type
     * and by class buildings (with the planet types they are allowed on), research, ships, defense and missiles.
     *
     * @param array<string, mixed> $reslist changed in place
     * @param array<string, mixed> $varsRow row of `vars`
     * @param array<string, array<int, mixed>> $grid `production` and `storage` of the element
     */
    private function sortIntoLists(array &$reslist, array $varsRow, array $grid): void
    {
        if (array_filter($grid['production'])) {
            $reslist['prod'][$varsRow['elementID']] = $varsRow['elementID'];
        }

        if (array_filter($grid['storage'])) {
            $reslist['storage'][$varsRow['elementID']] = $varsRow['elementID'];
        }

        if ($varsRow['onePerPlanet'] == 1) {
            $reslist['one'][$varsRow['elementID']] = $varsRow['elementID'];
        }
        if (isset($varsRow['ship_type'])) {
            $reslist['ship_type'][$varsRow['ship_type']][$varsRow['elementID']] = $varsRow['elementID'];
        }

        switch ($varsRow['class']) {
            case 0:
                $reslist['build'][$varsRow['elementID']] = $varsRow['elementID'];
                $tmp = explode(',', $varsRow['onPlanetType']);
                foreach ($tmp as $type) {
                    $reslist['allow'][$type][$varsRow['elementID']] = $varsRow['elementID'];
                }
                break;
            case 100:
                $reslist['tech'][$varsRow['elementID']] = $varsRow['elementID'];
                break;
            case 200:
                $reslist['fleet'][$varsRow['elementID']] = $varsRow['elementID'];
                break;
            case 400:
                $reslist['defense'][$varsRow['elementID']] = $varsRow['elementID'];
                break;
            case 500:
                $reslist['missile'][$varsRow['elementID']] = $varsRow['elementID'];
                break;
        }
    }
}
