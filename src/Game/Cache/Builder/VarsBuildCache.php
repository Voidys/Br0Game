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

class VarsBuildCache implements BuildCacheInterface
{
    public function __construct(
        private ?Database $db = null,
        private ?\App\Game\Core\Singleton $state = null,
    ) {}

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
                $CombatCaps[$varsRow['elementID']] = [
                    'attack'    => $varsRow['attack'],
                    'shield'    => $varsRow['defend'],
                    'bigshield' => $varsRow['bigshield'],
                    'stun'      => $varsRow['stun'],
                ];

                $pricelist[$varsRow['elementID']] = [
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

                if (array_filter($ProdGrid[$varsRow['elementID']]['production'])) {
                    $reslist['prod'][$varsRow['elementID']] = $varsRow['elementID'];
                }

                if (array_filter($ProdGrid[$varsRow['elementID']]['storage'])) {
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

            $rapidResult = $db->select('SELECT * FROM %%VARS_RAPIDFIRE%% WHERE universe = :universe;', [':universe' => $universe]);
            foreach ($rapidResult as $rapidRow) {
                $CombatCaps[$rapidRow['elementID']]['sd'][$rapidRow['rapidfireID']] = $rapidRow['shoots'];
            }

            $result[$universe] = [
                'reslist'       => $reslist,
                'ProdGrid'      => $ProdGrid,
                'CombatCaps'    => $CombatCaps,
                'resource'      => $resource,
                'pricelist'     => $pricelist,
                'requeriments'  => $requeriments,
            ];
        }
        return $result;
    }
}
