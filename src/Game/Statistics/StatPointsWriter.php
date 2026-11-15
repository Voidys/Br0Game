<?php

namespace App\Game\Statistics;

use App\Game\Core\Database;
use App\Game\Models\StatPoints;

/**
 * Writes the statpoints rows of users and alliances in batches: rows are queued and written as multi-row upserts
 * (one query per 200 rows instead of one per row). Call flush() after the last row.
 */
class StatPointsWriter
{
    /** Rows queued by queue(), waiting for flush() */
    private array $pendingStatRows = [];

    private const STAT_COLUMNS = [
        'id_owner', 'id_ally', 'stat_type', 'universe',
        'tech_rank', 'tech_old_rank', 'tech_points', 'tech_count',
        'build_rank', 'build_old_rank', 'build_points', 'build_count',
        'defs_rank', 'defs_old_rank', 'defs_points', 'defs_count',
        'fleet_rank', 'fleet_old_rank', 'fleet_points', 'fleet_count',
        'total_rank', 'total_old_rank', 'total_points', 'total_count',
        'honor_points', 'honor_rank', 'honor_old_rank', 'honorable_fights', 'dishonorable_fights',
        'units_points', 'units_rank', 'units_old_rank',
    ];

    public function __construct(private readonly Database $db) {}

    /**
     * Queues the row of a user/alliance for the statpoints table (written when 200 rows are queued, or by flush()).
     */
    public function queue(StatPoints $data): void
    {
        $row = [];
        foreach (self::STAT_COLUMNS as $column) {
            $row[] = $data->$column;
        }
        $this->pendingStatRows[] = $row;

        if (count($this->pendingStatRows) >= 200) {
            $this->flush();
        }
    }

    /** Writes the queued rows (insert, or update of the existing row of the same owner). */
    public function flush(): void
    {
        if ($this->pendingStatRows === []) {
            return;
        }

        $columnCount = count(self::STAT_COLUMNS);
        $placeholders = '(' . implode(', ', array_fill(0, $columnCount, '?')) . ')';
        $update = [];
        foreach (self::STAT_COLUMNS as $column) {
            if ($column !== 'id_owner') {
                $update[] = "`$column` = VALUES(`$column`)";
            }
        }

        $query = "INSERT IGNORE INTO `%%STATPOINTS%%` (`" . implode('`, `', self::STAT_COLUMNS) . "`) VALUES "
            . implode(', ', array_fill(0, count($this->pendingStatRows), $placeholders))
            . " ON DUPLICATE KEY UPDATE " . implode(', ', $update);

        $params = [];
        foreach ($this->pendingStatRows as $row) {
            foreach ($row as $value) {
                $params[] = $value;
            }
        }
        $this->pendingStatRows = [];

        $this->db->insert($query, $params);
    }
}
