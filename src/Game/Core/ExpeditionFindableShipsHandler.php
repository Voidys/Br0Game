<?php

namespace App\Game\Core;

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

    private function buildDefaultConfig(bool $findable = false): array
    {
        $reslist = $this->state->reslist;
        $config = [];

        foreach ($reslist['fleet'] as $targetShipId) {
            $findableByResult = [];
            foreach (self::LEGACY_FINDABLE_SHIPS as $shipId => $findableBy) {
                if (in_array($targetShipId, $findableBy) && in_array($shipId, $reslist['fleet'])) {
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

    public function getConfigForUniverse(int $universe, bool $findable = false): array
    {
        $reslist = $this->state->reslist;
        $sql = "SELECT `ship_id`, `is_findable`, `findable_by`
            FROM %%EXPEDITION_FINDABLE_SHIPS%%
            WHERE `universe` = :universe;";
        $rows = $this->db->select($sql, [
                ':universe' => $universe,
            ]
        );

        $result = $this->buildDefaultConfig($findable);
        foreach ($rows as $row) {
            if ($findable && ($row['is_findable'] == 0 || !in_array($row['ship_id'], $reslist['fleet']))) {
                unset($result[$row['ship_id']]);
                continue;
            }

            $findableBy = json_decode($row['findable_by'], true);
            $row['findable_by'] = is_array($findableBy) ? $findableBy : [];
            $result[$row['ship_id']] = $row;
        }

        return $result;
    }

    public function saveConfigForUniverse(int $universe, array $submittedConfig): void
    {
        $reslist = $this->state->reslist;
        $db = $this->db;

        $sql = 'INSERT INTO %%EXPEDITION_FINDABLE_SHIPS%% (`universe`, `ship_id`, `is_findable`, `findable_by`)
                VALUES (:universe, :ship_id, :is_findable, :findable_by)
                ON DUPLICATE KEY UPDATE `is_findable` = VALUES(`is_findable`), `findable_by` = VALUES(`findable_by`);';

        foreach ($reslist['fleet'] as $shipId) {
            $shipConfig = $submittedConfig[$shipId] ?? ['is_findable' => 0, 'findable_by' => []];
            $isFindable = !empty($shipConfig['is_findable']) && !empty($shipConfig['findable_by']) && is_array($shipConfig['findable_by']);
            $db->insert($sql, [
                    ':universe' => $universe,
                    ':ship_id' => $shipId,
                    ':is_findable' => $isFindable ? 1 : 0,
                    ':findable_by' => json_encode($shipConfig['findable_by'] ?? []),
                ]
            );
        }
    }

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