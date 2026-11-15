<?php

/**
 *  2Moons
 *   by Timo_Ka 2023
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package pr0game
 * @copyright 2023 Timo_Ka
 * @licence MIT
 * @version 1.8.0
 * @link https://codeberg.org/pr0game/pr0game
 */

namespace App\Controller\Admin;

use Exception;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;

/** Per-position planet defaults: temperature, fields and production bonuses. */
#[\App\Routing\GamePage('admin.php', 'configPlanets')]
#[\App\Routing\GamePage('admin.php', 'configPlanets', uniPrefix: true)]
class ConfigPlanetsController extends AbstractAdminController
{
    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowMultiIPPage')) {
            throw new Exception($this->state->LNG['adm_permission_error']);
        }

        parent::construct();
    }

    public function send(): void
    {
        $action = $this->http()->_GP('mode', '');

        if ($action != "send") {
            return;
        }

        $planetData = [];
        $db = $this->db;
        $sql = "SELECT * FROM %%PLANET_DATA%% WHERE universe = :universe ORDER BY planet_position ASC";
        $planetDataDefault = $db->select($sql, [
            ':universe' => 0,
        ]);

        for ($i = 0; $i <= 14; $i++) {
            $planetData[$i] = [
                'universe'              => 0,
                'planet_position'       => $i + 1,
                'temp_min'              => $this->http()->_GP("temp_min_" . $i + 1, $planetDataDefault[$i]['temp_min']),
                'temp_max'              => $this->http()->_GP("temp_max_" . $i + 1, $planetDataDefault[$i]['temp_max']),
                'fields_min'            => $this->http()->_GP("fields_min_" . $i + 1, $planetDataDefault[$i]['fields_min']),
                'fields_max'            => $this->http()->_GP("fields_max_" . $i + 1, $planetDataDefault[$i]['fields_max']),
                'metalBonusPercent'     => $this->http()->_GP("metalBonusPercent_" . $i + 1, $planetDataDefault[$i]['metalBonusPercent']),
                'crystalBonusPercent'   => $this->http()->_GP("crystalBonusPercent_" . $i + 1, $planetDataDefault[$i]['crystalBonusPercent']),
                'deuteriumBonusPercent' => $this->http()->_GP("deuteriumBonusPercent_" . $i + 1, $planetDataDefault[$i]['deuteriumBonusPercent']),
            ];
        }

        for ($i = 0; $i <= 14; $i++) {
            if (!array_diff_assoc($planetDataDefault[$i], $planetData[$i])) {
                continue;
            }

            $sql = "SELECT COUNT(*) as count 
                FROM %%PLANET_DATA%%  
                WHERE universe = :universe AND planet_position = :planet_position
                ORDER BY planet_position ASC;";

            $count = $db->selectSingle($sql, [
                ':universe'        => $this->universe()->getEmulated(),
                ':planet_position' => $i + 1,
            ], 'count');

            if ($count == 0) {
                $sql = "INSERT INTO %%PLANET_DATA%% SET      
            universe                = :universe,
            planet_position         = :planet_position,
            temp_min                = :temp_min,
            temp_max                = :temp_max,
            fields_min              = :fields_min,
            fields_max              = :fields_max,
            metalBonusPercent       = :metalBonusPercent,
            crystalBonusPercent     = :crystalBonusPercent,
            deuteriumBonusPercent   = :deuteriumBonusPercent;";

                $db->insert($sql, [
                    ':universe'              => $this->universe()->getEmulated(),
                    ':planet_position'       => $i + 1,
                    ':temp_min'              => $planetData[$i]['temp_min'],
                    ':temp_max'              => $planetData[$i]['temp_max'],
                    ':fields_min'            => $planetData[$i]['fields_min'],
                    ':fields_max'            => $planetData[$i]['fields_max'],
                    ':metalBonusPercent'     => $planetData[$i]['metalBonusPercent'],
                    ':crystalBonusPercent'   => $planetData[$i]['crystalBonusPercent'],
                    ':deuteriumBonusPercent' => $planetData[$i]['deuteriumBonusPercent'],
                ]);
            } else {
                $sql = "UPDATE %%PLANET_DATA%% SET      
            temp_min                = :temp_min,
            temp_max                = :temp_max,
            fields_min              = :fields_min,
            fields_max              = :fields_max,
            metalBonusPercent       = :metalBonusPercent,
            crystalBonusPercent     = :crystalBonusPercent,
            deuteriumBonusPercent   = :deuteriumBonusPercent
            WHERE universe = :universe AND planet_position = :planet_position;";

                $db->update($sql, [
                    ':universe'              => $this->universe()->getEmulated(),
                    ':planet_position'       => $i + 1,
                    ':temp_min'              => $planetData[$i]['temp_min'],
                    ':temp_max'              => $planetData[$i]['temp_max'],
                    ':fields_min'            => $planetData[$i]['fields_min'],
                    ':fields_max'            => $planetData[$i]['fields_max'],
                    ':metalBonusPercent'     => $planetData[$i]['metalBonusPercent'],
                    ':crystalBonusPercent'   => $planetData[$i]['crystalBonusPercent'],
                    ':deuteriumBonusPercent' => $planetData[$i]['deuteriumBonusPercent'],
                ]);
            }
        }
    }

    public function show(): Response
    {
        $this->send();


        $mergedArray = $this->playerUtil()->getPlanetDataPlanetConfig();

        $this->assign([
            'planet_data'  => $mergedArray,
        ]);

        return $this->render('admin/ConfigBodyPlanets.html.twig');
    }
}
