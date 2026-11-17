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

use App\Game\Core\Universe;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Per-position planet defaults: temperature, fields and production bonuses. */
class ConfigPlanetsController extends AbstractAdminController
{
    /**
     * Only authlevel ADM may open the page (checked before the permission system).
     */
    protected function construct(): void
    {
        if (!$this->viewHelpers()->allowedTo('ShowMultiIPPage')) {
            $this->deny();
        }

        parent::construct();
    }

    /**
     * Saves the per-position planet defaults: temperature, fields and production bonuses per planet position, stored in
     * PLANET_DATA (insert for new positions, update for existing ones), then shows the table again.
     */
    #[GameRoute('admin', 'admin/config/planets', 'configPlanets', 'send', ['POST'])]
    public function send(): Response
    {
        $db = $this->db;
        $sql = "SELECT * FROM %%PLANET_DATA%% WHERE universe = :universe ORDER BY planet_position ASC";
        $planetDataDefault = $db->select($sql, [
            ':universe' => 0,
        ]);

        $planetData = $this->requestedPlanetData($planetDataDefault);

        for ($i = 0; $i <= 14; $i++) {
            if (!array_diff_assoc($planetDataDefault[$i], $planetData[$i])) {
                continue;
            }

            $this->savePlanetPosition($i, $planetData[$i]);
        }

        return $this->show();
    }

    /**
     * Reads the posted values for the 15 planet positions (`<field>_<position>`); a missing value keeps the default.
     *
     * @param array<int, array<string, mixed>> $planetDataDefault the default rows (universe 0) by position - 1
     * @return array<int, array<string, mixed>> rows by position - 1
     */
    private function requestedPlanetData(array $planetDataDefault): array
    {
        $planetData = [];
        for ($i = 0; $i <= 14; $i++) {
            $planetData[$i] = [
                'universe'              => 0,
                'planet_position'       => $i + 1,
                'temp_min'              => $this->input()->int("temp_min_" . $i + 1, (int) $planetDataDefault[$i]['temp_min']),
                'temp_max'              => $this->input()->int("temp_max_" . $i + 1, (int) $planetDataDefault[$i]['temp_max']),
                'fields_min'            => $this->input()->int("fields_min_" . $i + 1, (int) $planetDataDefault[$i]['fields_min']),
                'fields_max'            => $this->input()->int("fields_max_" . $i + 1, (int) $planetDataDefault[$i]['fields_max']),
                'metalBonusPercent'     => $this->input()->float("metalBonusPercent_" . $i + 1, (float) $planetDataDefault[$i]['metalBonusPercent']),
                'crystalBonusPercent'   => $this->input()->float("crystalBonusPercent_" . $i + 1, (float) $planetDataDefault[$i]['crystalBonusPercent']),
                'deuteriumBonusPercent' => $this->input()->float("deuteriumBonusPercent_" . $i + 1, (float) $planetDataDefault[$i]['deuteriumBonusPercent']),
            ];
        }

        return $planetData;
    }

    /**
     * Stores the values of one planet position for the emulated universe: insert when it has no row yet, else update.
     *
     * @param int $i position - 1
     * @param array<string, mixed> $values the planet data row
     */
    private function savePlanetPosition(int $i, array $values): void
    {
        $db = $this->db;
        $planetData = [$i => $values];

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

    /**
     * Renders the table of per-position planet defaults.
     */
    #[GameRoute('admin', 'admin/config/planets', 'configPlanets')]
    public function show(): Response
    {
        $mergedArray = $this->planetManager()->getPlanetDataPlanetConfig();

        return $this->render('admin/ConfigBodyPlanets.html.twig', [
            'planet_data'  => $mergedArray,
        ]);
    }
}
