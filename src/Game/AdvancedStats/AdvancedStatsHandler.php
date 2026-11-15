<?php

namespace App\Game\AdvancedStats;

use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

class AdvancedStatsHandler
{
    use \App\Game\Core\GameUtilities;


    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {
    }

    public function increment(int $userId, AdvancedStatsEnum|null $statKey, int $value = 1): void
    {
        if ($statKey == null) {
            return;
        }

        $sql = "INSERT INTO %%ADVANCED_STATS%% 
                (userId, universe, statkey, statvalue) 
                VALUES (:userId, :universe, :statkey, :statvalue)
                ON DUPLICATE KEY UPDATE statvalue = statvalue + :statvalue";

        $this->db->insert($sql, [
            ':userId'       => $userId,
            ':universe'     => $this->playerUtil()->getUniverse($userId),
            ':statkey'      => $statKey->name,
            ':statvalue'    => $value,
        ]);
    }

    public function setValue(int $userId, AdvancedStatsEnum|null $statKey, int|float $value): void
    {
        if ($statKey == null) {
            return;
        }

        $sql = "INSERT INTO %%ADVANCED_STATS%% 
                (userId, universe, statkey, statvalue) 
                VALUES (:userId, :universe, :statkey, :statvalue)
                ON DUPLICATE KEY UPDATE statvalue = :statvalue";

        $this->db->insert($sql, [
            ':userId'       => $userId,
            ':universe'     => $this->playerUtil()->getUniverse($userId),
            ':statkey'      => $statKey->name,
            ':statvalue'    => $value,
        ]);
    }

    public function setTimestamp(int $userId, AdvancedStatsEnum|null $statKey, ?int $timestamp = null): void
    {
        $timestamp ??= $this->state->runtime->timestamp;
        if ($statKey == null) {
            return;
        }
        $universe = $this->playerUtil()->getUniverse($userId);

        $sql = "INSERT IGNORE INTO %%ADVANCED_STATS%% 
                (userId, universe, statkey, statvalue) 
                VALUES (:userId, :universe, :statkey, :statvalue)";
        $this->db->insert($sql, [
            ':userId'       => $userId,
            ':universe'     => $universe,
            ':statkey'      => $statKey->name,
            ':statvalue'    => $timestamp,
        ]);

        $sql = "SELECT username FROM %%USERS%% WHERE id = :userId";
        $username = $this->db->selectSingle($sql, [':userId' => $userId], 'username');

        $sql = "INSERT IGNORE INTO %%ADVANCED_STATS_UNIVERSE%% 
                (universe, statkey, userId, username, statvalue) 
                VALUES (:universe, :statkey, :userId, :username, :statvalue)";
        $this->db->insert($sql, [
            ':universe'     => $universe,
            ':statkey'      => $statKey->name,
            ':userId'       => $userId,
            ':username'     => $username,
            ':statvalue'    => $timestamp,
        ]);
    }

    public function getValue(int $userId, AdvancedStatsEnum|null $statKey): int|float
    {
        if ($statKey == null) {
            return 0;
        }

        $sql = "SELECT statvalue 
                FROM %%ADVANCED_STATS%% 
                WHERE userId = :userId AND statkey = :statkey";

        return (float) $this->db->selectSingle($sql, [
            ':userId'       => $userId,
            ':statkey'      => $statKey->name,
        ])['statvalue'] ?? 0;
    }

    public function getMultipleStats(int $userId, array $statKeys, bool $history = false): array
    {
        if (empty($statKeys)) {
            return [];
        }

        $placeholders = '';
        foreach ($statKeys as $enum) {
            $placeholders .= ':' . $enum->name . ', ';
        }
        $placeholders = rtrim($placeholders, ", ");
        if ($history) {
            $sql = "SELECT DISTINCT `timestamp`
                    FROM %%ADVANCED_STATS_HISTORY%% 
                    ORDER BY timestamp DESC
                    LIMIT 1";
            $tmp = $this->db->selectSingle($sql);
            $timestamp = (int) ($tmp['timestamp'] ?? 0);
            $sql = "SELECT statkey, statvalue 
                    FROM %%ADVANCED_STATS_HISTORY%% 
                    WHERE userId = :userId AND `timestamp` = :ts AND statkey IN ($placeholders)";
            $params = [':userId' => $userId, ':ts' => $timestamp];
        } else {
            $sql = "SELECT statkey, statvalue 
                    FROM %%ADVANCED_STATS%% 
                    WHERE userId = :userId AND statkey IN ($placeholders)";
            $params = [':userId' => $userId];
        }

        foreach ($statKeys as $enum) {
            $params[':' . $enum->name] = $enum->name;
        }

        $result = $this->db->select($sql, $params);

        $stats = [];
        foreach ($statKeys as $enum) {
            $stats[strtolower($enum->name)] = 0;
        }
        foreach ($result as $row) {
            $stats[strtolower($row['statkey'])] = (float)$row['statvalue'];
        }

        return $stats;
    }

    public function getAllStats(int $userId): array
    {
        $sql = "SELECT statkey, statvalue 
                FROM %%ADVANCED_STATS%% 
                WHERE userId = :userId";

        $result = $this->db->select($sql, [
            ':userId'       => $userId,
        ]);

        $stats = [];
        foreach (AdvancedStatsEnum::cases() as $enum) {
            $stats[strtolower($enum->name)] = 0;
        }
        foreach ($result as $row) {
            $stats[strtolower($row['statkey'])] = (float)$row['statvalue'];
        }

        return $stats;
    }

    public function getLangKey(string $key): string
    {
        $LNG = & $this->state->LNG;
        if (!empty($LNG['Advanced_Stats'][$key])) {
            return $LNG['Advanced_Stats'][$key];
        }

        $element = substr(strrchr($key, "_"), 1);
        $prev = substr($key, 0, strrpos($key, "_"));
        if (!empty($LNG['Advanced_Stats'][$prev]) && !empty($LNG['tech'][$element])) {
            return $LNG['Advanced_Stats'][$prev] . ' ' . $LNG['tech'][$element];
        }

        if (!empty($LNG['tech'][$element])) {
            return $LNG['tech'][$element];
        }

        return 'Missing text for ' . $key;
    }

    public function getHonorList(int $universe, int $limit = 100, string $kind = 'top'): array
    {
        if ($kind == 'top') {
            $sql = "SELECT userId, statvalue AS honorpoints 
                    FROM %%ADVANCED_STATS%% 
                    WHERE universe = :universe AND statkey = :hp_key
                    ORDER BY statvalue DESC
                    LIMIT :limit";
        } else {
            $sql = "SELECT userId, statvalue AS honorpoints 
                    FROM %%ADVANCED_STATS%% 
                    WHERE universe = :universe AND statkey = :hp_key
                    ORDER BY statvalue ASC
                    LIMIT :limit";
        }

        $result = $this->db->select($sql, [
            ':universe' => $universe,
            ':hp_key'   => AdvancedStatsEnum::HONORPOINTS->name,
            ':limit'    => $limit,
        ]);

        foreach ($result as &$row) {
            $sql = "SELECT statvalue 
                    FROM %%ADVANCED_STATS%% 
                    WHERE statkey = :hp_key AND userId = :user_id";
            $row['honorable_fights'] = (int)$this->db->selectSingle($sql, [
                ':hp_key'   => AdvancedStatsEnum::HONORABLE_FIGHTS->name,
                ':user_id'  => $row['userId'],
            ])['statvalue'] ?? 0;
            $row['dishonorable_fights'] = (int)$this->db->selectSingle($sql, [
                ':hp_key'   => AdvancedStatsEnum::DISHONORABLE_FIGHTS->name,
                ':user_id'  => $row['userId'],
            ])['statvalue'] ?? 0;
        }

        if ($kind == 'top') {
            usort($result, function ($a, $b) {
                if (($a['honorpoints'] <=> $b['honorpoints']) !== 0) {
                    return $b['honorpoints'] <=> $a['honorpoints']; // desc
                }
                if (($a['honorable_fights'] <=> $b['honorable_fights']) !== 0) {
                    return $b['honorable_fights'] <=> $a['honorable_fights']; // desc
                }
                return $a['dishonorable_fights'] <=> $b['dishonorable_fights']; // asc
            });
        } else {
            usort($result, function ($a, $b) {
                if (($a['honorpoints'] <=> $b['honorpoints']) !== 0) {
                    return $a['honorpoints'] <=> $b['honorpoints']; // asc
                }
                if (($a['honorable_fights'] <=> $b['honorable_fights']) !== 0) {
                    return $a['honorable_fights'] <=> $b['honorable_fights']; // asc
                }
                return $b['dishonorable_fights'] <=> $a['dishonorable_fights']; // desc
            });
        }

        return $result;
    }

    public function getSummedStats(array $userIds, array $statKeys): array
    {
        if (empty($userIds) || empty($statKeys)) {
            return [];
        }

        $userPlaceholders = str_repeat('?,', count($userIds) - 1) . '?';
        $statPlaceholders = str_repeat('?,', count($statKeys) - 1) . '?';

        $sql = "SELECT statkey, SUM(statvalue) as summed_value 
                FROM %%ADVANCED_STATS%% 
                WHERE userId IN ($userPlaceholders)
                AND statkey IN ($statPlaceholders)
                GROUP BY statkey";

        $params = [];
        foreach ($userIds as $userId) {
            $params[] = $userId;
        }
        foreach ($statKeys as $enum) {
            $params[] = $enum->name;
        }

        $result = $this->db->select($sql, $params);

        $stats = [];
        foreach ($statKeys as $enum) {
            $stats[strtolower($enum->name)] = 0;
        }

        foreach ($result as $row) {
            $stats[strtolower($row['statkey'])] = (float)$row['summed_value'];
        }

        return $stats;
    }

    /**
     * Will update destroyed ships/def stats. If defender ID is not 0 (= not AI) its lost stats will be updated.
     *
     * @param integer $attacker
     * @param integer $defender
     * @param integer $Element
     * @param integer $amount
     * @param boolean $tf
     * @return void
     */
    public function updateDestroyedAdvancedStats(int $attacker, int $defender, int $Element, int $amount = 1, bool $tf = true)
    {
        if ($attacker > 0) {
            $pricelist = & $this->state->pricelist;

            $units = ($pricelist[$Element]['cost'][901] + $pricelist[$Element]['cost'][902]) * $amount;

            $this->increment($attacker, AdvancedStatsEnum::getDestroyed($Element), $amount);
            $this->increment($attacker, AdvancedStatsEnum::DESUNITS_REAL, $units);

            if ($tf === true) {
                $this->updateDebrisAdvancedStats($attacker, $Element, $amount);
            }
        }

        if ($defender > 0) {
            $fleetArray = [$Element => $amount];
            $this->updateLostAdvancedStats($defender, $fleetArray);
        }
    }

    public function updateDebrisAdvancedStats($user, $Element, $amount = 0)
    {
        if ($user <= 0) {
            return;
        }

        $reslist = & $this->state->reslist;
        $pricelist = & $this->state->pricelist;
        $config = $this->configs->get();

        $debrisFactor = in_array($Element, $reslist['fleet']) ? $config->fleet_debris_percentage : $config->def_debris_percentage;
        $unitsMetal = $pricelist[$Element]['cost'][901] * $amount;
        $unitsCrystal = $pricelist[$Element]['cost'][902] * $amount;
        $debrisMetal = $unitsMetal * $debrisFactor / 100;
        $debrisCrystal =  $unitsCrystal * $debrisFactor / 100;

        $this->increment($user, AdvancedStatsEnum::KBMETAL_REAL, $debrisMetal);
        $this->increment($user, AdvancedStatsEnum::KBCRYSTAL_REAL, $debrisCrystal);
    }

    public function updateLostAdvancedStats($user, $fleetArray)
    {
        if ($user <= 0) {
            return;
        }

        $pricelist = & $this->state->pricelist;

        foreach ($fleetArray as $Element => $Count) {
            $this->increment($user, AdvancedStatsEnum::getLost($Element), $Count);
            $this->increment($user, AdvancedStatsEnum::LOSTUNITS_REAL, ($pricelist[$Element]['cost'][901] + $pricelist[$Element]['cost'][902]) * $Count);
        }
    }

    public function updateExpoLostAdvancedStats($user, $fleetArray)
    {
        if ($user <= 0) {
            return;
        }

        foreach ($fleetArray as $Element => $Count) {
            $this->increment($user, AdvancedStatsEnum::getExpoLost($Element), $Count);
        }
    }

    public function updateFoundShipsAdvancedStats($user, $fleetArray)
    {
        if ($user <= 0) {
            return;
        }

        foreach ($fleetArray as $Element => $Count) {
            $this->increment($user, AdvancedStatsEnum::getExpoFound($Element), $Count);
        }
    }
}
