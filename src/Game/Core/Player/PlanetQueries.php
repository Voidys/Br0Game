<?php

namespace App\Game\Core\Player;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Read-only planet queries: the main planet of a user, the planet list for menus and the field limit of a
 * planet. Collaborators: Database, state (resource map).
 */
class PlanetQueries
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * The user's main planet row by the home coordinates stored on the user.
     *
     * @param array $user user row (universe, galaxy, system, planet)
     * @return array planet row, or [] if missing
     */
    public function getMainPlanet(array $user): array
    {
        /**
         * @var Database
         */
        $db = $this->db;

        $sql = "SELECT * FROM %%PLANETS%% 
            WHERE `universe` = :universe AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet
            AND `planet_type` = 1;";
        $planet = $db->selectSingle($sql, [
            ':universe' => $user['universe'],
            ':galaxy' => $user['galaxy'],
            ':system' => $user['system'],
            ':planet' => $user['planet'],
        ]);
        return is_array($planet) ? $planet : [];
    }

    /**
     * Maximum number of building fields of a planet: base size plus terraformer and moon base bonus.
     *
     * @param array $planet planet row
     * @return int|float
     */
    public function calculateMaxPlanetFields($planet)
    {
        $resource = & $this->state->resource;
        return $planet['field_max'] + floor($planet[$resource[33]] * FIELDS_BY_TERRAFORMER) + ($planet[$resource[41]]
            * FIELDS_BY_MOONBASIS_LEVEL);
    }

    /**
     * Non-destroyed planets and moons of a user for lists/menus, ordered by the user's planet_sort settings
     * (id, coordinates, name; ascending or descending). Returns the cached `PLANETS` entry of the user row if present.
     *
     * @param array $USER user row
     * @return array<int, array> planet id => reduced planet row
     */
    public function getPlanets($USER)
    {
        if (isset($USER['PLANETS'])) {
            return $USER['PLANETS'];
        }

        $order = $USER['planet_sort_order'] == 1 ? "DESC" : "ASC";

        $sql = "SELECT id, name, galaxy, system, planet, planet_type, image, planet_style, b_building, b_building_id, watch_id
    			FROM %%PLANETS%% WHERE id_owner = :userId AND destruyed = :destruyed ORDER BY ";

        switch ($USER['planet_sort']) {
            case 0:
                $sql .= 'id ' . $order;
                break;
            case 1:
                $sql .= 'galaxy ' . $order . ', system ' . $order . ', planet ' . $order . ', planet_type ' . $order;
                break;
            case 2:
                $sql .= 'name ' . $order;
                break;
        }

        $planetsResult = $this->db->select($sql, [
            ':userId' => $USER['id'],
            ':destruyed' => 0,
        ]);

        $planetsList = [];

        foreach ($planetsResult as $planetRow) {
            $planetsList[$planetRow['id']] = $planetRow;
        }

        return $planetsList;
    }
}
