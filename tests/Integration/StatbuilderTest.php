<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Statbuilder;
use App\Tests\Support\GameTestCase;

/**
 * Pins the rows the statistics build writes (statpoints of users and alliances: points, counts, ranks, honor ranks and
 * titles, the records table, the history archive) for a small fixture universe: three players with buildings, research,
 * ships and defense, one flying fleet, two of them in an alliance, one with honor points, one banned.
 *
 * Why: Statbuilder is split into score, rank, record and archive services; the cron results must stay identical.
 */
final class StatbuilderTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'stat rows' => ['1:0' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 1, 'tech_points' => 78.0, 'tech_count' => 10, 'build_rank' => 2, 'build_points' => 36.0, 'build_count' => 30, 'defs_rank' => 1, 'defs_points' => 80.0, 'defs_count' => 40, 'fleet_rank' => 2, 'fleet_points' => 500.0, 'fleet_count' => 125, 'total_rank' => 2, 'total_points' => 694.0, 'total_count' => 205, 'honor_points' => 60000, 'honor_rank' => 1, 'honorable_fights' => 3, 'dishonorable_fights' => 0, 'honor_title' => 'top1', 'units_points' => 700, 'units_rank' => 1], '1:1' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 2, 'tech_points' => 8.0, 'tech_count' => 3, 'build_rank' => 3, 'build_points' => 10.0, 'build_count' => 12, 'defs_rank' => 2, 'defs_points' => 18.0, 'defs_count' => 9, 'fleet_rank' => 1, 'fleet_points' => 720.0, 'fleet_count' => 12, 'total_rank' => 1, 'total_points' => 756.0, 'total_count' => 36, 'honor_points' => 100, 'honor_rank' => 2, 'honorable_fights' => 0, 'dishonorable_fights' => 0, 'honor_title' => 'neutral', 'units_points' => 0, 'units_rank' => 2], '1:2' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 3, 'tech_points' => 3.0, 'tech_count' => 2, 'build_rank' => 1, 'build_points' => 498.0, 'build_count' => 20, 'defs_rank' => 3, 'defs_points' => 0.0, 'defs_count' => 0, 'fleet_rank' => 3, 'fleet_points' => 165.0, 'fleet_count' => 10, 'total_rank' => 3, 'total_points' => 666.0, 'total_count' => 32, 'honor_points' => -60000, 'honor_rank' => 4, 'honorable_fights' => 0, 'dishonorable_fights' => 2, 'honor_title' => 'low1', 'units_points' => -50, 'units_rank' => 4], '1:3' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 4, 'tech_points' => 0.0, 'tech_count' => 0, 'build_rank' => 4, 'build_points' => 0.0, 'build_count' => 0, 'defs_rank' => 4, 'defs_points' => 0.0, 'defs_count' => 0, 'fleet_rank' => 4, 'fleet_points' => 0.0, 'fleet_count' => 0, 'total_rank' => 4, 'total_points' => 0.0, 'total_count' => 0, 'honor_points' => 0, 'honor_rank' => 3, 'honorable_fights' => 0, 'dishonorable_fights' => 0, 'honor_title' => 'neutral', 'units_points' => 0, 'units_rank' => 3], '2:4' => ['stat_type' => 2, 'universe' => 1, 'tech_rank' => 1, 'tech_points' => 86.0, 'tech_count' => 13, 'build_rank' => 1, 'build_points' => 46.0, 'build_count' => 42, 'defs_rank' => 1, 'defs_points' => 98.0, 'defs_count' => 49, 'fleet_rank' => 1, 'fleet_points' => 1220.0, 'fleet_count' => 137, 'total_rank' => 1, 'total_points' => 1450.0, 'total_count' => 241, 'honor_points' => 60100, 'honor_rank' => 1, 'honorable_fights' => 3, 'dishonorable_fights' => 0, 'honor_title' => 'neutral', 'units_points' => 700, 'units_rank' => 1]],
        'stat rows, stat=2' => ['1:0' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 1, 'tech_points' => 78.0, 'tech_count' => 10, 'build_rank' => 2, 'build_points' => 36.0, 'build_count' => 30, 'defs_rank' => 1, 'defs_points' => 80.0, 'defs_count' => 40, 'fleet_rank' => 2, 'fleet_points' => 500.0, 'fleet_count' => 125, 'total_rank' => 2, 'total_points' => 694.0, 'total_count' => 205, 'honor_points' => 60000, 'honor_rank' => 1, 'honorable_fights' => 3, 'dishonorable_fights' => 0, 'honor_title' => 'top1', 'units_points' => 700, 'units_rank' => 1], '1:1' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 2, 'tech_points' => 8.0, 'tech_count' => 3, 'build_rank' => 3, 'build_points' => 10.0, 'build_count' => 12, 'defs_rank' => 2, 'defs_points' => 18.0, 'defs_count' => 9, 'fleet_rank' => 1, 'fleet_points' => 720.0, 'fleet_count' => 12, 'total_rank' => 1, 'total_points' => 756.0, 'total_count' => 36, 'honor_points' => 100, 'honor_rank' => 2, 'honorable_fights' => 0, 'dishonorable_fights' => 0, 'honor_title' => 'neutral', 'units_points' => 0, 'units_rank' => 2], '1:2' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 3, 'tech_points' => 3.0, 'tech_count' => 2, 'build_rank' => 1, 'build_points' => 498.0, 'build_count' => 20, 'defs_rank' => 3, 'defs_points' => 0.0, 'defs_count' => 0, 'fleet_rank' => 3, 'fleet_points' => 165.0, 'fleet_count' => 10, 'total_rank' => 3, 'total_points' => 666.0, 'total_count' => 32, 'honor_points' => -60000, 'honor_rank' => 4, 'honorable_fights' => 0, 'dishonorable_fights' => 2, 'honor_title' => 'low1', 'units_points' => -50, 'units_rank' => 4], '1:3' => ['stat_type' => 1, 'universe' => 1, 'tech_rank' => 4, 'tech_points' => 0.0, 'tech_count' => 0, 'build_rank' => 4, 'build_points' => 0.0, 'build_count' => 0, 'defs_rank' => 4, 'defs_points' => 0.0, 'defs_count' => 0, 'fleet_rank' => 4, 'fleet_points' => 0.0, 'fleet_count' => 0, 'total_rank' => 4, 'total_points' => 0.0, 'total_count' => 0, 'honor_points' => 0, 'honor_rank' => 3, 'honorable_fights' => 0, 'dishonorable_fights' => 0, 'honor_title' => 'neutral', 'units_points' => 0, 'units_rank' => 3], '2:4' => ['stat_type' => 2, 'universe' => 1, 'tech_rank' => 1, 'tech_points' => 86.0, 'tech_count' => 13, 'build_rank' => 1, 'build_points' => 46.0, 'build_count' => 42, 'defs_rank' => 1, 'defs_points' => 98.0, 'defs_count' => 49, 'fleet_rank' => 1, 'fleet_points' => 1220.0, 'fleet_count' => 137, 'total_rank' => 1, 'total_points' => 1450.0, 'total_count' => 241, 'honor_points' => 60100, 'honor_rank' => 1, 'honorable_fights' => 3, 'dishonorable_fights' => 0, 'honor_title' => 'neutral', 'units_points' => 700, 'units_rank' => 1]],
        'records' => [[3, 1, 30], [0, 2, 8], [1, 3, 7], [0, 4, 10], [3, 108, 15], [0, 109, 4], [1, 113, 3]],
        'history' => [['stat_type' => 1, 'total_points' => 694.0, 'total_rank' => 2, 'honor_points' => 60000, 'honor_rank' => 1, 'honor_title' => 'top1'], ['stat_type' => 1, 'total_points' => 756.0, 'total_rank' => 1, 'honor_points' => 100, 'honor_rank' => 2, 'honor_title' => 'neutral'], ['stat_type' => 1, 'total_points' => 666.0, 'total_rank' => 3, 'honor_points' => -60000, 'honor_rank' => 4, 'honor_title' => 'low1'], ['stat_type' => 1, 'total_points' => 0.0, 'total_rank' => 4, 'honor_points' => 0, 'honor_rank' => 3, 'honor_title' => 'neutral'], ['stat_type' => 2, 'total_points' => 1450.0, 'total_rank' => 1, 'honor_points' => 60100, 'honor_rank' => 1, 'honor_title' => 'neutral']],
        'advanced history count' => 9,
    ];
    // GOLDEN-END

    /** @return list<int> user ids */
    private function fixture(): array
    {
        $now = time();
        // other tests may have left rows behind; the ranks depend on every user of the universe (rolled back with the test)
        foreach (['STATPOINTS' => 'universe', 'PLANETS' => 'universe', 'USERS' => 'universe', 'FLEETS' => 'universe'] as $table => $column) {
            $this->db->delete("DELETE FROM %%$table%% WHERE $column = 1;");
        }
        $this->db->insert("INSERT INTO %%ALLIANCE%% (ally_name, ally_tag, ally_owner, ally_register_time, ally_universe, ally_events) VALUES ('Statfix', 'SFX', 0, :t, 1, '');", [':t' => $now]);
        $allyId = (int) $this->db->lastInsertId();

        [$a] = $this->createPlayer(['onlinetime' => $now, 'ally_id' => $allyId, 'computer_tech' => 6, 'military_tech' => 4], ['metal_mine' => 12, 'crystal_mine' => 8, 'solar_plant' => 10, 'small_ship_cargo' => 25, 'light_hunter' => 100, 'misil_launcher' => 40]);
        [$b] = $this->createPlayer(['onlinetime' => $now, 'ally_id' => $allyId, 'energy_tech' => 3], ['metal_mine' => 5, 'deuterium_sintetizer' => 7, 'battle_ship' => 12, 'small_laser' => 9]);
        [$c] = $this->createPlayer(['onlinetime' => $now, 'computer_tech' => 2], ['metal_mine' => 20, 'crusher' => 3]);
        [$banned] = $this->createPlayer(['onlinetime' => $now, 'bana' => 1, 'computer_tech' => 15], ['metal_mine' => 30]);

        // a flying fleet of player c counts for the fleet score
        $this->db->insert("INSERT INTO %%FLEETS%% (universe, mission, owner, target_owner, ship_amount, ships, start_time, arrival_time, stay_time, return_time, start_id, end_id) VALUES (1, 3, :o, :o, 7, '202,5;206,2', :t, :t, :t, :t, :p, :p);", [':o' => $c['id'], ':t' => $now, ':p' => 1]);

        foreach ([[$a['id'], 'HONORPOINTS', 60000], [$a['id'], 'HONORABLE_FIGHTS', 3], [$a['id'], 'DESUNITS_REAL', 900], [$a['id'], 'LOSTUNITS_REAL', 200],
            [$c['id'], 'HONORPOINTS', -60000], [$c['id'], 'DISHONORABLE_FIGHTS', 2], [$c['id'], 'LOSTUNITS_REAL', 50]] as [$userId, $key, $value]) {
            $this->db->insert('INSERT INTO %%ADVANCED_STATS%% (userId, universe, statkey, statvalue) VALUES (:u, 1, :k, :v) ON DUPLICATE KEY UPDATE statvalue = VALUES(statvalue);', [':u' => $userId, ':k' => $key, ':v' => $value]);
        }

        return [(int) $a['id'], (int) $b['id'], (int) $c['id'], (int) $banned['id'], $allyId];
    }

    /**
     * @param array<mixed> $ids
     * @return array<string, array<string, mixed>> statpoints rows of the fixture keyed by "<type>:<index>"
     */
    private function statRows(array $ids): array
    {
        $rows = [];
        foreach ($this->db->select('SELECT * FROM %%STATPOINTS%% WHERE universe = 1 ORDER BY stat_type, id_owner;') as $row) {
            // users are ids[0..3], the alliance ids[4] (alliance and user ids may coincide)
            $index = array_search((int) $row['id_owner'], (int) $row['stat_type'] === 1 ? array_slice($ids, 0, 4) : [4 => $ids[4]], true);
            if ($index === false) {
                continue;
            }
            $key = $row['stat_type'] . ':' . $index;
            // the old ranks come from the player's creation, which depends on the other rows of the database
            unset($row['id_owner'], $row['id_ally'], $row['tech_old_rank'], $row['build_old_rank'], $row['defs_old_rank'], $row['fleet_old_rank'], $row['total_old_rank'], $row['honor_old_rank'], $row['units_old_rank']);
            $rows[$key] = array_map(static fn($v) => is_numeric($v) ? $v + 0 : $v, $row);
        }

        return $rows;
    }

    public function testStatisticsBuildWritesPointsRanksAndTitles(): void
    {
        $ids = $this->fixture();
        $this->setUniverseConfig(['stat' => 1, 'stat_level' => 1, 'stat_settings' => 1000]);

        (new Statbuilder($this->db, $this->configs, $this->state))->generateStats();

        $this->goldenAssert('stat rows', $this->statRows($ids));
    }

    public function testStatisticsBuildWithHiddenAdminsRanking(): void
    {
        $ids = $this->fixture();
        $this->setUniverseConfig(['stat' => 2, 'stat_level' => 1, 'stat_settings' => 1000]);

        (new Statbuilder($this->db, $this->configs, $this->state))->generateStats();

        $this->goldenAssert('stat rows, stat=2', $this->statRows($ids));
    }

    public function testRecordsAndArchive(): void
    {
        $ids = $this->fixture();
        $this->setUniverseConfig(['stat' => 1, 'stat_level' => 1, 'stat_settings' => 1000]);
        $builder = new Statbuilder($this->db, $this->configs, $this->state);
        $builder->generateStats();

        $builder->buildRecords();
        $records = [];
        foreach ($this->db->select('SELECT userID, elementID, level, universe FROM %%RECORDS%% WHERE universe = 1 ORDER BY elementID, userID;') as $row) {
            $index = array_search((int) $row['userID'], $ids, true);
            $records[] = [$index === false ? 'other' : $index, (int) $row['elementID'], (int) $row['level']];
        }
        $this->goldenAssert('records', $records);

        $builder->archiveStats();
        $history = $this->db->select('SELECT stat_type, total_points, total_rank, honor_points, honor_rank, honor_title FROM %%STATPOINTS_HISTORY%% WHERE universe = 1 AND id_owner IN (' . implode(',', $ids) . ') ORDER BY stat_type, id_owner;');
        $this->goldenAssert('history', array_map(static fn(array $r) => array_map(static fn($v) => is_numeric($v) ? $v + 0 : $v, $r), $history));
        $this->goldenAssert('advanced history count', (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%ADVANCED_STATS_HISTORY%% WHERE universe = 1 AND userId IN (' . implode(',', array_slice($ids, 0, 4)) . ');', [], 'c'));
    }
}
