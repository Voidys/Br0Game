<?php

namespace App\Game\Core\Player;

use App\Game\Core\ConfigRegistry;
use Doctrine\DBAL\Query\QueryBuilder;
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
     * Array adapter of maxFieldsFor().
     *
     * @param array $planet planet row
     * @return int|float
     */
    public function calculateMaxPlanetFields($planet)
    {
        return $this->maxFieldsFor($this->models()->planet($planet));
    }

    /**
     * Typed version of calculateMaxPlanetFields(): `field_max` + floor(terraformer level * 5.5) + moon base level * 3.
     * The two levels can be overridden to ask what the limit would be after a queued terraformer or moon base
     * (BuildFunctions::queueFieldProjectionFor()); the planet itself is not changed.
     *
     * @param int|float|null $terraformerLevel level to use instead of the planet's terraformer level
     * @param int|float|null $moonBaseLevel    level to use instead of the planet's moon base level
     */
    public function maxFieldsFor(\App\Game\Model\Planet $planet, int|float|null $terraformerLevel = null, int|float|null $moonBaseLevel = null): int|float
    {
        $terraformerLevel ??= $planet->count(TERRAFORMER);
        $moonBaseLevel ??= $planet->count(MOONBASE);

        return $planet->fieldsMax + floor($terraformerLevel * FIELDS_BY_TERRAFORMER) + ($moonBaseLevel * FIELDS_BY_MOONBASIS_LEVEL);
    }

    /**
     * Adds the player's planet sort setting to a planet query: 1 = coordinates (galaxy, system, planet, type), 2 = name,
     * anything else (0) = id. All columns are fixed names; the direction is only ever ASC or DESC.
     *
     * @param mixed  $sort      user setting planet_sort
     * @param string $direction "ASC" or "DESC" (user setting planet_sort_order)
     */
    public static function orderBySetting(QueryBuilder $qb, mixed $sort, string $direction): void
    {
        $direction = $direction === 'DESC' ? 'DESC' : 'ASC';
        switch ((int) $sort) {
            case 1:
                $qb->orderBy('galaxy', $direction)
                    ->addOrderBy('`system`', $direction)
                    ->addOrderBy('planet', $direction)
                    ->addOrderBy('planet_type', $direction);
                break;
            case 2:
                $qb->orderBy('name', $direction);
                break;
            default:
                $qb->orderBy('id', $direction);
                break;
        }
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

        $qb = $this->db->qb()
            ->select('id', 'name', 'galaxy', '`system`', 'planet', 'planet_type', 'image', 'planet_style', 'b_building', 'b_building_id', 'watch_id')
            ->from($this->db->table('PLANETS'))
            ->where('id_owner = :userId')
            ->andWhere('destruyed = :destruyed')
            ->setParameter('userId', $USER['id'])
            ->setParameter('destruyed', 0);
        self::orderBySetting($qb, $USER['planet_sort'], $order);
        $planetsResult = $this->db->fetchAll($qb);

        $planetsList = [];

        foreach ($planetsResult as $planetRow) {
            $planetsList[$planetRow['id']] = $planetRow;
        }

        return $planetsList;
    }
}
