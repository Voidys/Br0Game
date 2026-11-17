<?php

namespace App\Game\Core;

/**
 * Configuration of the ships an expedition can find: for each ship type which ship types in the fleet allow it
 * to be found ("findable_by"). Stored per universe in expedition_findable_ships; without rows the legacy table
 * LEGACY_FINDABLE_SHIPS applies (bigger ships can find the smaller ones). Used by ExpeditionShipEvent and edited in the admin area.
 */
class ExpeditionFindableShipsHandler
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    private const LEGACY_FINDABLE_SHIPS = [
        210 => [210, 202],
        202 => [210, 202, 204],
        204 => [210, 202, 204, 203],
        203 => [210, 202, 204, 203, 205],
        205 => [210, 202, 204, 203, 205, 206],
        206 => [210, 202, 204, 203, 205, 206, 207],
        207 => [210, 202, 204, 203, 205, 206, 207, 215],
        215 => [210, 202, 204, 203, 205, 206, 207, 215, 211],
        211 => [210, 202, 204, 203, 205, 206, 207, 215, 211, 213],
        213 => [210, 202, 204, 203, 205, 206, 207, 215, 211, 213],
    ];

    /**
     * Default configuration from the legacy table for all enabled ship types.
     * @param bool $findable true: only ships that are findable by something.
     * @return array<int, array{ship_id:int, is_findable:int, findable_by:int[]}>
     */
    private function buildDefaultConfig(bool $findable = false): array
    {
        $elements = $this->elements();
        $config = [];

        foreach ($elements->ids('fleet') as $targetShipId) {
            $findableByResult = [];
            foreach (self::LEGACY_FINDABLE_SHIPS as $shipId => $findableBy) {
                if (in_array($targetShipId, $findableBy) && in_array($shipId, $elements->ids('fleet'))) {
                    $findableByResult[] = $shipId;
                }
            }

            if ($findable && empty($findableByResult)) {
                continue;
            }

            $config[$targetShipId] = [
                'ship_id' => $targetShipId,
                'is_findable' => !empty($findableByResult) ? 1 : 0,
                'findable_by' => $findableByResult,
            ];
        }

        return $config;
    }

    /**
     * Effective configuration of a universe: the defaults overridden by the stored rows. With $findable, ships that
     * are disabled, not findable or no longer exist are dropped.
     * @return array<int, array{ship_id:int, is_findable:int, findable_by:int[]}> by ship id.
     */
    public function getConfigForUniverse(int $universe, bool $findable = false): array
    {
        $elements = $this->elements();
        $sql = "SELECT `ship_id`, `is_findable`, `findable_by`
            FROM %%EXPEDITION_FINDABLE_SHIPS%%
            WHERE `universe` = :universe;";
        $rows = $this->db->select(
            $sql,
            [
                ':universe' => $universe,
            ],
        );

        $result = $this->buildDefaultConfig($findable);
        foreach ($rows as $row) {
            if ($findable && ($row['is_findable'] == 0 || !in_array($row['ship_id'], $elements->ids('fleet')))) {
                unset($result[$row['ship_id']]);
                continue;
            }

            $findableBy = json_decode($row['findable_by'], true);
            $row['findable_by'] = is_array($findableBy) ? $findableBy : [];
            $result[$row['ship_id']] = $row;
        }

        return $result;
    }

    /**
     * Stores the admin's submitted configuration for every ship type (a ship counts as findable only when flagged and
     * at least one finder is selected).
     * @param array<int, array{is_findable?:mixed, findable_by?:int[]}> $submittedConfig by ship id.
     */
    public function saveConfigForUniverse(int $universe, array $submittedConfig): void
    {
        $elements = $this->elements();
        $db = $this->db;

        $sql = 'INSERT INTO %%EXPEDITION_FINDABLE_SHIPS%% (`universe`, `ship_id`, `is_findable`, `findable_by`)
                VALUES (:universe, :ship_id, :is_findable, :findable_by)
                ON DUPLICATE KEY UPDATE `is_findable` = VALUES(`is_findable`), `findable_by` = VALUES(`findable_by`);';

        foreach ($elements->ids('fleet') as $shipId) {
            $shipConfig = $submittedConfig[$shipId] ?? ['is_findable' => 0, 'findable_by' => []];
            $isFindable = !empty($shipConfig['is_findable']) && !empty($shipConfig['findable_by']) && is_array($shipConfig['findable_by']);
            $db->insert(
                $sql,
                [
                    ':universe' => $universe,
                    ':ship_id' => $shipId,
                    ':is_findable' => $isFindable ? 1 : 0,
                    ':findable_by' => json_encode($shipConfig['findable_by'] ?? []),
                ],
            );
        }
    }

    /**
     * Ship types the fleet could find on an expedition: every findable ship that has one of the fleet's ship types
     * among its finders; the fallback [210, 202] (spy probe, small cargo) when nothing matches.
     * @param array<int,int|float> $fleet ship id => amount.
     * @return int[] ship ids
     */
    public function findableShipsForFleet(int $universe, array $fleet): array
    {
        $config = $this->getConfigForUniverse($universe, true);
        $result = [];

        foreach ($config as $findableId => $shipConfig) {
            foreach ($fleet as $finderId => $amount) {
                if (in_array($finderId, $shipConfig['findable_by'])) {
                    $result[] = $findableId;
                    break;
                }
            }
        }
        if (empty($result)) {
            return [210, 202];
        }
        return $result;
    }
}
