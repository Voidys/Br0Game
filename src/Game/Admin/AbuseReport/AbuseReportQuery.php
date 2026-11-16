<?php

declare(strict_types=1);

namespace App\Game\Admin\AbuseReport;

use App\Game\Core\Database;

/**
 * Read-only queries behind the admin "Abuse report" (audit F-02, F-15, F-16, F-19 and the operator checklist): one-sided
 * transports/holds, repeated market trades with a skewed value ratio, rebuild-protection grants and recalls at or after
 * the arrival time. Every query is limited to LIMIT rows and reads only the log tables (nothing is written).
 *
 * Value weighting: all cargo is converted to metal units with the universe's stored market ratios
 * (marketplace.metDeutRatio / crysDeutRatio, defaults 3.0 and 2.0): 1 metal = 1, 1 crystal = metDeut / crysDeut,
 * 1 deuterium = metDeut. With the defaults that is 1 : 1.5 : 3.
 *
 * Linked accounts: two players count as linked when they share the same non-empty users.user_lastip or a multi group
 * (multi_to_users); a group both players were marked as known/allowed in is reported as "known".
 */
final class AbuseReportQuery
{
    public const LIMIT = 100;
    public const DEFAULT_DAYS = 7;
    public const MAX_DAYS = 90;
    /** smallest one-way cargo value (metal units) per pair that is listed */
    public const MIN_TRANSFER_VALUE = 1000;
    /** a pair is one-sided when the return flow is at most this share of the outgoing value (i.e. 80 % one way) */
    public const MAX_RETURN_SHARE = 0.25;
    /** trades between the same pair that make it a "repeated" pair */
    public const MIN_REPEATED_TRADES = 3;
    /** value ratio between the two sides of a pair that is flagged (the market's push tolerance of 30 %) */
    public const TRADE_OUTLIER_RATIO = 1.3;

    private const DEFAULT_MET_DEUT = 3.0;
    private const DEFAULT_CRYS_DEUT = 2.0;

    public function __construct(private readonly Database $db) {}

    /**
     * @return array{
     *     days: int,
     *     since: int,
     *     weights: array{metal: float, crystal: float, deuterium: float},
     *     transfers: list<array<string, mixed>>,
     *     trades: list<array<string, mixed>>,
     *     protections: list<array<string, mixed>>,
     *     recalls: list<array<string, mixed>>
     * }
     */
    public function report(int $universe, int $days, int $now): array
    {
        $days = max(1, min(self::MAX_DAYS, $days));
        $since = $now - $days * 86400;
        $weights = $this->weights($universe);

        return [
            'days' => $days,
            'since' => $since,
            'weights' => $weights,
            'transfers' => $this->transfers($universe, $since, $weights),
            'trades' => $this->trades($universe, $since, $weights),
            'protections' => $this->protections($universe, $since, $weights),
            'recalls' => $this->recalls($universe, $since),
        ];
    }

    /** @return array{metal: float, crystal: float, deuterium: float} value of one unit in metal units */
    public function weights(int $universe): array
    {
        $row = $this->db->selectSingle('SELECT `metDeutRatio`, `crysDeutRatio` FROM %%MARKETPLACE%% WHERE `universeId` = :universe;', [':universe' => $universe]);
        $metDeut = is_array($row) ? (float) $row['metDeutRatio'] : 0.0;
        $crysDeut = is_array($row) ? (float) $row['crysDeutRatio'] : 0.0;
        $metDeut = $metDeut > 0.0 ? $metDeut : self::DEFAULT_MET_DEUT;
        $crysDeut = $crysDeut > 0.0 ? $crysDeut : self::DEFAULT_CRYS_DEUT;

        return ['metal' => 1.0, 'crystal' => $metDeut / $crysDeut, 'deuterium' => $metDeut];
    }

    /**
     * Pairs where transports/holds (not recalled) of the window carried at least MIN_TRANSFER_VALUE one way and at most
     * MAX_RETURN_SHARE of that value came back, biggest net flow first.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array<string, mixed>> from, to, count, sent, back, backCount, net, last (timestamp) + link flags and names
     */
    private function transfers(int $universe, int $since, array $weights): array
    {
        $value = sprintf('SUM(`resource_metal` + `resource_crystal` * %F + `resource_deuterium` * %F)', $weights['crystal'], $weights['deuterium']);
        // Database::select() only accepts statements starting with SELECT, so the per-direction sums are a derived table used twice
        $flow = "SELECT `owner` AS a, `target_owner` AS b, COUNT(*) AS n, $value AS v, MAX(`start_time`) AS last
                FROM %%LOG_FLEETS%%
                WHERE `universe` = :universe AND `mission` IN (:transport, :hold) AND `owner` <> `target_owner` AND `target_owner` > 0
                    AND `hasCanceled` = 0 AND `start_time` >= :since
                GROUP BY `owner`, `target_owner`";
        $sql = "SELECT f.a AS `from`, f.b AS `to`, f.n AS `count`, f.v AS sent, f.last, COALESCE(r.v, 0) AS back, COALESCE(r.n, 0) AS backCount,
                f.v - COALESCE(r.v, 0) AS net
            FROM ($flow) f LEFT JOIN ($flow) r ON r.a = f.b AND r.b = f.a
            WHERE f.v >= :minValue AND COALESCE(r.v, 0) <= f.v * :maxShare
            ORDER BY net DESC
            LIMIT " . self::LIMIT . ';';

        $rows = $this->db->select($sql, [
            ':universe' => $universe,
            ':transport' => MISSION_TRANSPORT,
            ':hold' => MISSION_HOLD,
            ':since' => $since,
            ':minValue' => self::MIN_TRANSFER_VALUE,
            ':maxShare' => self::MAX_RETURN_SHARE,
        ]);

        return $this->withLinks($rows, 'from', 'to', $universe);
    }

    /**
     * Resource trades (market type 0) between the same two players, at least MIN_REPEATED_TRADES times in the window.
     * Both sides of every trade are valued with the reference ratios; `ratio` is the larger side divided by the smaller
     * one (1.00 = at the reference) and the pair is an outlier above TRADE_OUTLIER_RATIO. Largest imbalance first.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array<string, mixed>>
     */
    private function trades(int $universe, int $since, array $weights): array
    {
        $unit = static fn(string $type, string $amount): string => sprintf(
            '(%s * CASE %s WHEN 1 THEN 1 WHEN 2 THEN %F WHEN 3 THEN %F ELSE 0 END)',
            $amount,
            $type,
            $weights['crystal'],
            $weights['deuterium'],
        );
        $offered = $unit('t.`resource_type`', 't.`resource_amount`');
        $wanted = $unit('t.`ex_resource_type`', 't.`ex_resource_amount`');

        $sql = "SELECT LEAST(s.`owner`, b.`owner`) AS lo, GREATEST(s.`owner`, b.`owner`) AS hi, COUNT(*) AS `count`,
                SUM(CASE WHEN s.`owner` < b.`owner` THEN $offered ELSE $wanted END) AS loGave,
                SUM(CASE WHEN s.`owner` < b.`owner` THEN $wanted ELSE $offered END) AS hiGave,
                UNIX_TIMESTAMP(MAX(t.`buy_time`)) AS last
            FROM %%TRADES%% t
            JOIN %%LOG_FLEETS%% s ON s.`id` = t.`seller_fleet_id`
            JOIN %%LOG_FLEETS%% b ON b.`id` = t.`buyer_fleet_id`
            WHERE t.`transaction_type` = 0 AND t.`buy_time` >= :since AND s.`universe` = :universe AND s.`owner` <> b.`owner`
            GROUP BY lo, hi
            HAVING `count` >= :minTrades
            ORDER BY ABS(loGave - hiGave) DESC
            LIMIT " . self::LIMIT . ';';

        $rows = $this->db->select($sql, [
            ':since' => $this->db->formatDate($since),
            ':universe' => $universe,
            ':minTrades' => self::MIN_REPEATED_TRADES,
        ]);

        $out = [];
        foreach ($rows as $row) {
            $lo = (float) $row['loGave'];
            $hi = (float) $row['hiGave'];
            $big = max($lo, $hi);
            $small = min($lo, $hi);
            $row['net'] = $big - $small;
            $row['giver'] = $lo >= $hi ? (int) $row['lo'] : (int) $row['hi'];
            $row['receiver'] = $lo >= $hi ? (int) $row['hi'] : (int) $row['lo'];
            $row['ratio'] = $small > 0.0 ? $big / $small : null;
            $row['outlier'] = $small <= 0.0 ? $big > 0.0 : $big / $small > self::TRADE_OUTLIER_RATIO;
            $out[] = $row;
        }

        return $this->withLinks($out, 'giver', 'receiver', $universe);
    }

    /**
     * Players that got a rebuild protection in the window (rebuild_protection_locked_until is set to the fight time plus
     * one month). The fight is found through the combat report stored at that exact time; the opponents of the
     * protected player (the other side of the report) are checked for links and for the loot of their fleets of that report.
     *
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array<string, mixed>> user, name, granted, report id, opponents (id, name, loot, linked flags), loot, linked
     */
    private function protections(int $universe, int $since, array $weights): array
    {
        $users = $this->db->select(
            'SELECT `id`, `username`, `rebuild_protection_locked_until` - ' . TIME_1_MONTH . ' AS granted
            FROM %%USERS%% WHERE `universe` = :universe AND `rebuild_protection_locked_until` >= :minLocked
            ORDER BY `rebuild_protection_locked_until` DESC LIMIT ' . self::LIMIT . ';',
            [':universe' => $universe, ':minLocked' => $since + TIME_1_MONTH],
        );

        $out = [];
        foreach ($users as $user) {
            $userId = (int) $user['id'];
            $reports = $this->db->select(
                'SELECT `rid`, `attacker`, `defender` FROM %%RW%% WHERE `time` = :time AND (FIND_IN_SET(:asDefender, `defender`) OR FIND_IN_SET(:asAttacker, `attacker`)) LIMIT 1;',
                [':time' => (int) $user['granted'], ':asDefender' => $userId, ':asAttacker' => $userId],
            );
            $entry = [
                'user' => $userId,
                'name' => (string) $user['username'],
                'granted' => (int) $user['granted'],
                'report' => '',
                'opponents' => [],
                'loot' => 0.0,
                'linked' => false,
                'linkedKnown' => false,
            ];

            if ($reports !== []) {
                $report = $reports[0];
                $inDefenders = in_array((string) $userId, explode(',', (string) $report['defender']), true);
                $entry['report'] = (string) $report['rid'];
                $opponentIds = $this->ids((string) ($inDefenders ? $report['attacker'] : $report['defender']));
                $entry['opponents'] = $this->opponents($opponentIds, $entry['report'], $weights, $userId, $universe);
                foreach ($entry['opponents'] as $opponent) {
                    $entry['loot'] += $opponent['loot'];
                    if ($opponent['ip'] || $opponent['multi']) {
                        $entry['linked'] = true;
                    }
                }
                $entry['linkedKnown'] = $entry['linked'] && array_reduce(
                    $entry['opponents'],
                    static fn(bool $all, array $o): bool => $all && (!($o['ip'] || $o['multi']) || $o['multiKnown']),
                    true,
                );
            }

            $out[] = $entry;
        }

        return $out;
    }

    /**
     * Fleets recalled when the arrival time was already reached (log stay_time = recall time >= arrival_time) on
     * attack, ACS and destroy missions: the recall-after-arrival pattern of audit F-05.
     *
     * @return list<array<string, mixed>>
     */
    private function recalls(int $universe, int $since): array
    {
        $rows = $this->db->select(
            'SELECT `id`, `mission`, `owner`, `owner_name`, `target_owner`, `target_owner_name`, `arrival_time`, `stay_time`,
                `stay_time` - `arrival_time` AS late, (`report_id` <> \'\') AS fought
            FROM %%LOG_FLEETS%%
            WHERE `universe` = :universe AND `mission` IN (:attack, :acs, :destroy) AND `hasCanceled` = 1 AND `start_time` >= :since
                AND `stay_time` >= `arrival_time`
            ORDER BY `stay_time` DESC LIMIT ' . self::LIMIT . ';',
            [
                ':universe' => $universe,
                ':attack' => MISSION_ATTACK,
                ':acs' => MISSION_ACS,
                ':destroy' => MISSION_DESTRUCTION,
                ':since' => $since,
            ],
        );

        return $rows;
    }

    /** @return list<int> */
    private function ids(string $list): array
    {
        return array_values(array_filter(array_map('intval', explode(',', $list)), static fn(int $id): bool => $id > 0));
    }

    /**
     * @param list<int> $opponentIds
     * @param array{metal: float, crystal: float, deuterium: float} $weights
     * @return list<array{id: int, name: string, loot: float, ip: bool, multi: bool, multiKnown: bool}>
     */
    private function opponents(array $opponentIds, string $reportId, array $weights, int $userId, int $universe): array
    {
        if ($opponentIds === []) {
            return [];
        }

        $in = implode(',', $opponentIds);
        $loot = [];
        $rows = $this->db->select(
            "SELECT `owner`, SUM(`gained_metal` + `gained_crystal` * :crystal + `gained_deuterium` * :deuterium) AS loot
            FROM %%LOG_FLEETS%% WHERE `owner` IN ($in) AND `report_id` = :report GROUP BY `owner`;",
            [':crystal' => $weights['crystal'], ':deuterium' => $weights['deuterium'], ':report' => $reportId],
        );
        foreach ($rows as $row) {
            $loot[(int) $row['owner']] = (float) $row['loot'];
        }

        $links = $this->linkData(array_merge([$userId], $opponentIds), $universe);
        $out = [];
        foreach ($opponentIds as $id) {
            $link = $this->compare($links, $userId, $id);
            $out[] = [
                'id' => $id,
                'name' => $links['names'][$id] ?? ('#' . $id),
                'loot' => $loot[$id] ?? 0.0,
            ] + $link;
        }

        return $out;
    }

    /**
     * Adds the names and the link flags (ip, multi, multiKnown) of the two players in every row.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function withLinks(array $rows, string $keyA, string $keyB, int $universe): array
    {
        $ids = [];
        foreach ($rows as $row) {
            $ids[] = (int) $row[$keyA];
            $ids[] = (int) $row[$keyB];
        }
        $links = $this->linkData($ids, $universe);

        foreach ($rows as &$row) {
            $a = (int) $row[$keyA];
            $b = (int) $row[$keyB];
            $row[$keyA . 'Name'] = $links['names'][$a] ?? ('#' . $a);
            $row[$keyB . 'Name'] = $links['names'][$b] ?? ('#' . $b);
            $row += $this->compare($links, $a, $b);
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<int> $ids
     * @return array{names: array<int, string>, ips: array<int, string>, groups: array<int, array<int, bool>>} multi groups per user (id => allowed)
     */
    private function linkData(array $ids, int $universe): array
    {
        $ids = array_values(array_unique(array_filter($ids, static fn(int $id): bool => $id > 0)));
        $data = ['names' => [], 'ips' => [], 'groups' => []];
        if ($ids === []) {
            return $data;
        }

        $in = implode(',', $ids);
        foreach ($this->db->select("SELECT `id`, `username`, `user_lastip` FROM %%USERS%% WHERE `id` IN ($in);") as $row) {
            $data['names'][(int) $row['id']] = (string) $row['username'];
            $data['ips'][(int) $row['id']] = (string) $row['user_lastip'];
        }
        foreach ($this->db->select(
            "SELECT mu.`userID`, mu.`multiID`, mu.`allowed` FROM %%MULTI_TO_USERS%% mu JOIN %%MULTI%% m ON m.`multiID` = mu.`multiID`
            WHERE mu.`userID` IN ($in) AND m.`universe` = :universe;",
            [':universe' => $universe],
        ) as $row) {
            $data['groups'][(int) $row['userID']][(int) $row['multiID']] = (int) $row['allowed'] === 1;
        }

        return $data;
    }

    /**
     * @param array{names: array<int, string>, ips: array<int, string>, groups: array<int, array<int, bool>>} $links
     * @return array{ip: bool, multi: bool, multiKnown: bool}
     */
    private function compare(array $links, int $a, int $b): array
    {
        $ipA = $links['ips'][$a] ?? '';
        $ip = $ipA !== '' && $ipA === ($links['ips'][$b] ?? '');
        $shared = array_intersect_key($links['groups'][$a] ?? [], $links['groups'][$b] ?? []);
        $known = $shared !== [];
        foreach (array_keys($shared) as $multiId) {
            $known = $known && $links['groups'][$a][$multiId] && $links['groups'][$b][$multiId];
        }

        return ['ip' => $ip, 'multi' => $shared !== [], 'multiKnown' => $known];
    }
}
