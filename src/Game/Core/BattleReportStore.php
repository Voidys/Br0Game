<?php

namespace App\Game\Core;

/**
 * Writes combat reports into the `rw` table: rust reports as gzip + base64 JSON, the PHP engine's as StoredData JSON.
 */
final class BattleReportStore
{
    public function __construct(private readonly Database $db) {}

    /**
     * Inserts one report row.
     *
     * @param array<string, mixed> $reportData generated report
     * @param string $engineName battle engine that fought (`rust` or the PHP engine's name)
     * @param int|string $attacker comma separated attacker ids (or a marker/owner id for simulations and expeditions)
     * @param string|null $defender comma separated defender ids; null leaves the column at its default
     */
    public function insert(string $reportId, array $reportData, string $engineName, int|string $time, int|string $attacker, ?string $defender = null): void
    {
        $sql = 'INSERT INTO %%RW%% SET
            `rid` = :reportId,
            `raport` = :reportData,
            `time` = :time,
            `attacker` = :attacker,
            ' . ($defender !== null ? '`defender` = :defender,' : '') . '
            `battleengine` = :battleengine;';

        $params = [
            ':reportId' => $reportId,
            ':reportData' => $engineName === 'rust'
                ? compressToBase64(json_encode($reportData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))
                : StoredData::encode($reportData),
            ':time' => $time,
            ':attacker' => $attacker,
            ':battleengine' => $engineName,
        ];
        if ($defender !== null) {
            $params[':defender'] = $defender;
        }

        $this->db->insert($sql, $params);
    }
}
