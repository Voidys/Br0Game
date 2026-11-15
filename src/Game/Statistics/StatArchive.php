<?php

namespace App\Game\Statistics;

use App\Game\Core\Database;
use App\Game\Core\Singleton;

/** Statistics history: snapshots statpoints and advanced stats into the history tables. */
class StatArchive
{
    public function __construct(
        private readonly Database $db,
        private readonly Singleton $state,
    ) {}

    /**
     * Copies the current statpoints and advanced stats of every open (or login-only) universe into the history tables,
     * stamped with the current time.
     */
    public function archive(): void
    {
        $db = $this->db;
        $sql = "SELECT DISTINCT `uni` FROM %%CONFIG_UNIVERSE%%
            WHERE `key` = 'uni_status' AND `value` IN (:status_open, :status_login)
            ORDER BY `uni` ASC;";
        $uniResult = $db->select($sql, [
            ':status_open' => STATUS_OPEN,
            ':status_login' => STATUS_LOGIN_ONLY,
        ]);

        foreach ($uniResult as $uni) {
            $sql = "INSERT INTO %%STATPOINTS_HISTORY%%
                SELECT :timestamp AS `timestamp`, `id_owner`, `id_ally`, `stat_type`, `universe`,
                `tech_rank`, `tech_points`, `tech_count`, `build_rank`, `build_points`, `build_count`,
                `defs_rank`, `defs_points`, `defs_count`, `fleet_rank`, `fleet_points`, `fleet_count`,
                `total_rank`, `total_points`, `total_count`,
                `honor_points`, `honor_rank`, `honorable_fights`, `dishonorable_fights`, `honor_title`
                FROM %%STATPOINTS%% WHERE `universe` = :universe;";
            $db->insert($sql, [':timestamp' => $this->state->runtime->timestamp, ':universe' => $uni['uni']]);

            $sql = "INSERT INTO %%ADVANCED_STATS_HISTORY%%
                SELECT :timestamp AS `timestamp`, st.*
                    FROM %%ADVANCED_STATS%% st
                    WHERE st.`universe` = :universe;";
            $db->insert($sql, [':timestamp' => $this->state->runtime->timestamp, ':universe' => $uni['uni']]);
        }
    }
}
