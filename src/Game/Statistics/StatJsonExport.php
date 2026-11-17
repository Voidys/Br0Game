<?php

namespace App\Game\Statistics;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/** Export of the player statistics as public JSON files. */
class StatJsonExport
{
    use GameUtilities;

    /** @param list<int> $universes ids of all universes */
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly array $universes,
    ) {}

    /**
     * Writes the public statistics JSON files for every universe that has the stats-json module available and is open
     * for players: `stats/stats_<name>_<date>_<hour>.json`, with the newest
     * history snapshot older than `statsjson_delay` (rank, scores and battle stats per player).
     *
     * Side effect: writes one file per universe.
     */
    public function export(): void
    {
        foreach ($this->universes as $uni) {
            $config = $this->configs->get($uni);

            if ($this->viewHelpers()->isModuleAvailable(MODULE_STATS_JSON, $uni) && $config->uni_status != STATUS_REG_ONLY && $config->uni_status != STATUS_CLOSED) {
                $scores = $this->loadScores($uni, $config);
                $name = $config->uni_name;
                $name = str_replace(' ', '_', $name);
                $fh = fopen($this->state->runtime->rootPath . 'stats/stats_' . $name . '_' . date('Y-m-d_H') . '.json', 'w+');
                fwrite($fh, json_encode($scores, JSON_PRETTY_PRINT));
                fclose($fh);
            }
        }
    }

    /**
     * Rank, scores and battle stats of every player of a universe at the newest history snapshot older than
     * `statsjson_delay` seconds, ordered by total rank.
     *
     * @return list<array<string, mixed>> one entry per player (playerId, playerName, ..., timestamp)
     */
    private function loadScores(int|string $uni, object $config): array
    {
        $database = $this->db;

        $columns = [
            'sh.id_owner AS playerId',
            'u.username AS playerName',
            'u.universe AS playerUniverse',
            'u.galaxy AS playerGalaxy',
            'sh.id_ally AS allianceId',
            'a.ally_name AS allianceName',
            'sh.total_rank AS rank',
            'sh.total_points AS score',
            'sh.tech_rank AS researchRank',
            'sh.tech_points AS researchScore',
            'sh.build_rank AS buildingRank',
            'sh.build_points AS buildingScore',
            'sh.defs_rank AS defensiveRank',
            'sh.defs_points AS defensiveScore',
            'sh.fleet_rank AS fleetRank',
            'sh.fleet_points AS fleetScore',
            'adw.statvalue AS battlesWon',
            'adl.statvalue AS battlesLost',
            'addr.statvalue AS battlesDraw',
            'adm.statvalue AS debrisMetal',
            'adk.statvalue AS debrisCrystal',
            'addu.statvalue AS unitsDestroyed',
            'adlu.statvalue AS unitsLost',
            'admr.statvalue AS realDebrisMetal',
            'adkr.statvalue AS realDebrisCrystal',
            'addur.statvalue AS realUnitsDestroyed',
            'adlur.statvalue AS realUnitsLost',
            'sh.timestamp as timestamp',
        ];

        $sql = trim("SELECT SQL_BIG_RESULT
            " . implode(',', $columns) . "
            FROM %%STATPOINTS_HISTORY%% sh
            INNER JOIN %%USERS%% u ON u.`id` = sh.`id_owner`
            LEFT JOIN %%ALLIANCE%% a ON a.`id` = sh.`id_ally`
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% adw on adw.userId = u.id AND adw.timestamp = sh.timestamp AND adw.statkey = :wons
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% adl on adl.userId = u.id AND adl.timestamp = sh.timestamp AND adl.statkey = :loos
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% addr on addr.userId = u.id AND addr.timestamp = sh.timestamp AND addr.statkey = :draw
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% adm on adm.userId = u.id AND adm.timestamp = sh.timestamp AND adm.statkey = :kbmetalt
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% adk on adk.userId = u.id AND adk.timestamp = sh.timestamp AND adk.statkey = :kbcrystalt
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% addu on addu.userId = u.id AND addu.timestamp = sh.timestamp AND addu.statkey = :desunitst
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% adlu on adlu.userId = u.id AND adlu.timestamp = sh.timestamp AND adlu.statkey = :lostunitst
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% admr on admr.userId = u.id AND admr.timestamp = sh.timestamp AND admr.statkey = :kbmetalr
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% adkr on adkr.userId = u.id AND adkr.timestamp = sh.timestamp AND adkr.statkey = :kbcrystalr
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% addur on addur.userId = u.id AND addur.timestamp = sh.timestamp AND addur.statkey = :desunitsr
            LEFT JOIN %%ADVANCED_STATS_HISTORY%% adlur on adlur.userId = u.id AND adlur.timestamp = sh.timestamp AND adlur.statkey = :lostunitsr
            WHERE sh.`stat_type` = 1 AND sh.universe = :universe
              AND sh.`timestamp` = (
                  SELECT MAX(s2.`timestamp`)
                  FROM %%STATPOINTS_HISTORY%% s2
                  WHERE s2.`timestamp` < (UNIX_TIMESTAMP() - :delay)
                    AND s2.universe = :universe
                    AND s2.`stat_type` = 1
              )
            ORDER BY sh.`total_rank`;");

        return $database->select($sql, [
            ':wons' => AdvancedStatsEnum::WONS->name,
            ':loos' => AdvancedStatsEnum::LOOS->name,
            ':draw' => AdvancedStatsEnum::DRAWS->name,
            ':kbmetalt' => AdvancedStatsEnum::KBMETAL->name,
            ':kbcrystalt' => AdvancedStatsEnum::KBCRYSTAL->name,
            ':desunitst' => AdvancedStatsEnum::DESUNITS->name,
            ':lostunitst' => AdvancedStatsEnum::LOSTUNITS->name,
            ':kbmetalr' => AdvancedStatsEnum::KBMETAL_REAL->name,
            ':kbcrystalr' => AdvancedStatsEnum::KBCRYSTAL_REAL->name,
            ':desunitsr' => AdvancedStatsEnum::DESUNITS_REAL->name,
            ':lostunitsr' => AdvancedStatsEnum::LOSTUNITS_REAL->name,
            ':universe' => $uni,
            ':delay' => $config->statsjson_delay,
        ]);
    }
}
