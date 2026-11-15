<?php

namespace App\Game\Statistics;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;

/**
 * The records table of the statistics: highest tech levels and building levels with their holders.
 */
class StatRecords
{
    /**
     * @param list<int>                                $universes ids of all universes
     * @param array<int, array<int, string>>           $resources universe => element id => column name
     * @param array<int, array<string, array<int>>>    $reslists  universe => list name => element ids
     */
    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly array $universes,
        private readonly array $resources,
        private readonly array $reslists,
    ) {}

    /** Records of the highest research levels of the (non-hidden) players of a universe, per tech. */
    private function getHighestUser(string $type, int $uni): array
    {
        $resource = $this->resources[$uni];
        $reslist = $this->reslists[$uni];

        $columns = [];
        foreach ($reslist[$type] as $Techno) {
            $columns[$Techno] = $resource[$Techno];
        }

        // Highest level per column among the non-hidden users, then every user holding such a level
        // (including hidden ones, as before) - two queries instead of two per column.
        return $this->getHighestRows('%%USERS%%', 'id', 'authlevel < :level', $columns, $uni);
    }

    /** Records of the highest building levels of the (non-hidden) players' planets of a universe, per building. */
    private function getHighestPlanet(string $type, int $uni): array
    {
        $resource = $this->resources[$uni];
        $reslist = $this->reslists[$uni];

        $columns = [];
        foreach ($reslist[$type] as $kind) {
            $columns[$kind] = $resource[$kind];
        }

        return $this->getHighestRows('%%PLANETS%%', 'id_owner', 'p.destruyed = 0 AND u.authlevel < :level', $columns, $uni);
    }

    /**
     * @param string $table        %%USERS%% (rows are users) or %%PLANETS%% (rows are planets, owner by id_owner)
     * @param string $idColumn     column of $table that identifies the user
     * @param string $maxCondition condition for the rows that count for the maximum
     * @param array  $columns      element id => column name
     * @return array list (per element with a level above 0) of rows [id, level, 0 => element id]
     */
    private function getHighestRows(string $table, string $idColumn, string $maxCondition, array $columns, int $uni): array
    {
        $db = $this->db;
        $isPlanet = $table === '%%PLANETS%%';
        $level = $this->configs->get()->stat_level;

        if ($columns === []) {
            return [];
        }

        $maxSelect = [];
        foreach ($columns as $element => $column) {
            $maxSelect[] = "MAX(" . ($isPlanet ? 'p.' : '') . "`$column`) AS `m$element`";
        }
        if ($isPlanet) {
            $sql = "SELECT " . implode(', ', $maxSelect) . " FROM %%PLANETS%% p
                JOIN %%USERS%% u ON p.id_owner = u.id
                WHERE p.universe = :universe AND $maxCondition;";
        } else {
            $sql = "SELECT " . implode(', ', $maxSelect) . " FROM %%USERS%%
                WHERE universe = :universe AND $maxCondition;";
        }
        $max = $db->selectSingle($sql, [':universe' => $uni, ':level' => $level]);

        $wanted = [];
        $conditions = [];
        foreach ($columns as $element => $column) {
            $value = $max['m' . $element] ?? null;
            if ($value !== null && $value > 0) {
                $wanted[$element] = $value;
                $conditions[] = "`$column` = " . (int) $value;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $select = ['`' . $idColumn . '` AS `id`'];
        foreach ($wanted as $element => $value) {
            $select[] = '`' . $columns[$element] . '` AS `c' . $element . '`';
        }
        $sql = "SELECT " . implode(', ', $select) . " FROM $table
            WHERE universe = :universe AND (" . implode(' OR ', $conditions) . ");";
        $rows = $db->select($sql, [':universe' => $uni]);

        $result = [];
        foreach ($wanted as $element => $value) {
            $dataFinal = [];
            $seen = [];
            foreach ($rows as $row) {
                if ($row['c' . $element] != $value) {
                    continue;
                }
                if ($isPlanet) {
                    // DISTINCT id_owner
                    if (isset($seen[$row['id']])) {
                        continue;
                    }
                    $seen[$row['id']] = true;
                }
                $dataFinal[] = ['id' => $row['id'], 'level' => $row['c' . $element], 0 => $element];
            }
            $result[] = $dataFinal;
        }

        return $result;
    }

    /**
     * Inserts the record rows of a universe into the records table (in chunks of 1000 rows).
     *
     * @param array $records list per element of rows [id, level, 0 => element id]
     */
    private function insertQueries(array $records, int $uni): void
    {
        $db = $this->db;
        $values = [];
        $params = [];
        foreach ($records as $record) {
            foreach ($record as $row) {
                $values[] = '(?, ?, ?, ?)';
                array_push($params, $row['id'], $row['0'], $row['level'], $uni);
            }
        }

        foreach (array_chunk($values, 1000, true) as $offset => $chunk) {
            $sql = "INSERT INTO %%RECORDS%% (userID, elementID, level, universe) VALUES " . implode(', ', $chunk) . ";";
            $db->insert($sql, array_slice($params, array_key_first($chunk) * 4, count($chunk) * 4));
        }
    }

    /**
     * Rebuilds the records table (highest tech and building level per element and universe): clears it and writes the
     * players holding the highest level of each element. Hidden admins (authlevel at or above stat_level) do not set records.
     */
    public function buildRecords(): void
    {
        $db = $this->db;
        $sql = "DELETE FROM %%RECORDS%%;";
        $db->delete($sql);

        foreach ($this->universes as $uni) {
            $tech = $this->getHighestUser('tech', $uni);
            $buildings = $this->getHighestPlanet('build', $uni);
            //$defenses = $this->getHighestPlanet('defense', $uni);
            //$fleet = $this->getHighestPlanet('fleet', $uni);
            //$missiles = $this->getHighestPlanet('missile', $uni);
            $this->insertQueries($tech, $uni);
            $this->insertQueries($buildings, $uni);
        }
    }
}
