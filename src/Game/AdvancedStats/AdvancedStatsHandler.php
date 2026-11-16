<?php

namespace App\Game\AdvancedStats;

use App\Game\Core\Config;
use Doctrine\DBAL\ArrayParameterType;
use App\Game\Core\Database;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

/**
 * Reads and writes the per-player "advanced statistics" counters (table advanced_stats: userId, universe,
 * statkey = AdvancedStatsEnum case name, statvalue) that missions, the battle engines, the queues and the
 * achievements feed (builds, losses, kills, loot, recycling, expedition results, honor points, "first time"
 * timestamps). Every writer takes the enum case (null is ignored) so callers can pass the result of the
 * AdvancedStatsEnum::getBuild()/getLost()/... lookups directly.
 */
class AdvancedStatsHandler
{
    use \App\Game\Core\GameUtilities;


    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {
    }

    /**
     * Adds $value to the counter (inserts it on first use; atomic upsert). The universe is looked up from the player.
     * @param AdvancedStatsEnum|null $statKey null (no stat exists for that element) is a no-op.
     * @param int|float|string $value amount to add; the column is an integer (bigint), so fractions (e.g. debris
     *        values) are cut off toward zero, explicitly (an implicit float-to-int conversion is deprecated).
     */
    public function increment(int $userId, AdvancedStatsEnum|null $statKey, int|float|string $value = 1): void
    {
        if ($statKey == null) {
            return;
        }
        $value = (int) $value;

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

    /**
     * Sets the counter to an absolute value (upsert).
     */
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

    /**
     * Records a "first time" event (first attack, first colony, ...): stores the timestamp only if the player has none
     * yet (INSERT IGNORE) and mirrors it into advanced_stats_universe (with the user name) for the universe-wide
     * first-achiever list.
     * @param int|null $timestamp event time; default now.
     */
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

    /**
     * Current counter value, 0 when the player never got that stat (or $statKey is null).
     */
    public function getValue(int $userId, AdvancedStatsEnum|null $statKey): int|float
    {
        if ($statKey == null) {
            return 0;
        }

        $sql = "SELECT statvalue 
                FROM %%ADVANCED_STATS%% 
                WHERE userId = :userId AND statkey = :statkey";

        return (float) ($this->db->selectSingle($sql, [
            ':userId'       => $userId,
            ':statkey'      => $statKey->name,
        ])['statvalue'] ?? 0);
    }

    /**
     * Several counters of one player at once.
     * @param AdvancedStatsEnum[] $statKeys
     * @param bool $history true: read from the latest snapshot in advanced_stats_history instead of the live counters.
     * @return array<string, float|int> lower-case stat name => value, 0 for missing ones.
     */
    public function getMultipleStats(int $userId, array $statKeys, bool $history = false): array
    {
        if (empty($statKeys)) {
            return [];
        }

        $names = array_map(static fn(AdvancedStatsEnum $enum): string => $enum->name, $statKeys);
        $query = $this->db->qb()
            ->select('statkey', 'statvalue')
            ->setParameter('userId', $userId)
            ->setParameter('names', $names, ArrayParameterType::STRING);
        if ($history) {
            // the latest snapshot
            $timestamp = (int) ($this->db->fetchValue(
                $this->db->qb()
                    ->select('DISTINCT `timestamp`')
                    ->from($this->db->table('ADVANCED_STATS_HISTORY'))
                    ->orderBy('timestamp', 'DESC')
                    ->setMaxResults(1)
            ) ?: 0);
            $query->from($this->db->table('ADVANCED_STATS_HISTORY'))
                ->where('userId = :userId')
                ->andWhere('`timestamp` = :ts')
                ->setParameter('ts', $timestamp);
        } else {
            $query->from($this->db->table('ADVANCED_STATS'))
                ->where('userId = :userId');
        }
        $result = $this->db->fetchAll($query->andWhere('statkey IN (:names)'));

        $stats = [];
        foreach ($statKeys as $enum) {
            $stats[strtolower($enum->name)] = 0;
        }
        foreach ($result as $row) {
            $stats[strtolower($row['statkey'])] = (float)$row['statvalue'];
        }

        return $stats;
    }

    /**
     * All counters of a player (live), with 0 for stats the player does not have yet.
     * @return array<string, float|int> lower-case stat name => value.
     */
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

    /**
     * Display text of a stat key from the Advanced_Stats language array; for keys with an element suffix
     * (e.g. `build_202`) the text of the prefix plus the element's tech name; 'Missing text for <key>' if none exists.
     */
    public function getLangKey(string $key): string
    {
        $LNG = $this->state->LNG;
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

    /**
     * Honor ranking of a universe: the first $limit players by honor points (best first with $kind 'top', worst first
     * otherwise), each with the number of honorable and dishonorable fights; ties are broken by honorable fights
     * (more is better), then dishonorable fights (fewer is better).
     * @param string $kind 'top' or anything else for the bottom list.
     * @return array<int, array{userId:int, honorpoints:float, honorable_fights:int, dishonorable_fights:int}>
     */
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
            $row['honorable_fights'] = (int) ($this->db->selectSingle($sql, [
                ':hp_key'   => AdvancedStatsEnum::HONORABLE_FIGHTS->name,
                ':user_id'  => $row['userId'],
            ])['statvalue'] ?? 0);
            $row['dishonorable_fights'] = (int) ($this->db->selectSingle($sql, [
                ':hp_key'   => AdvancedStatsEnum::DISHONORABLE_FIGHTS->name,
                ':user_id'  => $row['userId'],
            ])['statvalue'] ?? 0);
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

    /**
     * Sums stats over several players (e.g. all members of an alliance).
     * @param int[] $userIds
     * @param AdvancedStatsEnum[] $statKeys
     * @return array<string, float|int> lower-case stat name => sum, 0 for none.
     */
    public function getSummedStats(array $userIds, array $statKeys): array
    {
        if (empty($userIds) || empty($statKeys)) {
            return [];
        }

        $result = $this->db->fetchAll(
            $this->db->qb()
                ->select('statkey', 'SUM(statvalue) AS summed_value')
                ->from($this->db->table('ADVANCED_STATS'))
                ->where('userId IN (:userIds)')
                ->andWhere('statkey IN (:names)')
                ->groupBy('statkey')
                ->setParameter('userIds', array_values($userIds), ArrayParameterType::INTEGER)
                ->setParameter('names', array_map(static fn(AdvancedStatsEnum $enum): string => $enum->name, $statKeys), ArrayParameterType::STRING)
        );

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
            $pricelist = $this->state->pricelist;

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

    /**
     * Counts the "real" debris a player's kills would create (units * fleet/defense debris percentage) in
     * KBMETAL_REAL / KBCRYSTAL_REAL.
     * @param int $user attacker id (<= 0 = NPC: ignored).
     */
    public function updateDebrisAdvancedStats($user, $Element, $amount = 0)
    {
        if ($user <= 0) {
            return;
        }

        $reslist = $this->state->reslist;
        $pricelist = $this->state->pricelist;
        $config = $this->configs->get();

        $debrisFactor = in_array($Element, $reslist['fleet']) ? $config->fleet_debris_percentage : $config->def_debris_percentage;
        $unitsMetal = $pricelist[$Element]['cost'][901] * $amount;
        $unitsCrystal = $pricelist[$Element]['cost'][902] * $amount;
        $debrisMetal = $unitsMetal * $debrisFactor / 100;
        $debrisCrystal =  $unitsCrystal * $debrisFactor / 100;

        $this->increment($user, AdvancedStatsEnum::KBMETAL_REAL, $debrisMetal);
        $this->increment($user, AdvancedStatsEnum::KBCRYSTAL_REAL, $debrisCrystal);
    }

    /**
     * Counts lost units of a player: per element the lost stat and the lost resource value (metal+crystal) in LOSTUNITS_REAL.
     * @param int $user player id (<= 0 = NPC: ignored).
     * @param array<int,int|float> $fleetArray element id => amount.
     */
    public function updateLostAdvancedStats($user, $fleetArray)
    {
        if ($user <= 0) {
            return;
        }

        $pricelist = $this->state->pricelist;

        foreach ($fleetArray as $Element => $Count) {
            $this->increment($user, AdvancedStatsEnum::getLost($Element), $Count);
            $this->increment($user, AdvancedStatsEnum::LOSTUNITS_REAL, ($pricelist[$Element]['cost'][901] + $pricelist[$Element]['cost'][902]) * $Count);
        }
    }

    /**
     * Counts ships lost on expeditions (black hole, pirate/alien fights) per element.
     * @param array<int,int|float> $fleetArray element id => amount.
     */
    public function updateExpoLostAdvancedStats($user, $fleetArray)
    {
        if ($user <= 0) {
            return;
        }

        foreach ($fleetArray as $Element => $Count) {
            $this->increment($user, AdvancedStatsEnum::getExpoLost($Element), $Count);
        }
    }

    /**
     * Counts ships found on expeditions per element.
     * @param array<int,int|float> $fleetArray element id => amount.
     */
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
