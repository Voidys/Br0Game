<?php

/**
 * pr0game powered by steemnova
 * trade calculations for marketplace
 * (c) 2022 havoc
 *
 */

namespace App\Game\Core;

use Exception;

/**
 * Price reference of the marketplace: derives the current metal/deuterium and crystal/deuterium exchange rates from
 * the completed trades of the last 7 days in the current universe (between different players, visible ones only),
 * clamped to 0.5..5.0 and stored in `marketplace` (the stored ratio is the fallback when there are no trades), and
 * offers conversions to deuterium and fleet values for the push check (trades that would just transfer wealth
 * between multi-accounts or weak/strong players).
 */
class MarketManager
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public const REFERENCE_DECIMALS = 2;
    private const RATIO_MIN = 0.5;
    private const RATIO_MAX = 5.0;

    private int $_restype_metal = 1;
    private int $_restype_crystal = 2;
    private int $_restype_deuterium = 3;

    private array $_mdVolume = [];
    private array $_cdVolume = [];
    private ?array $_storedRatios = null;
    private array $_pairRatios = [];
    private bool $_ratiosPersisted = false;

    /**
     * Period, no grouping. Used for stored fleet-log rates, not the marketplace inputs.
     */
    public static function formatReferenceRatio(float $ratio): string
    {
        return number_format($ratio, self::REFERENCE_DECIMALS, '.', '');
    }

    /**
     * Last stored ratios of the universe (metDeutRatio, crysDeutRatio); defaults 3.0 and 2.0 for a new market. Cached per instance.
     */
    private function getStoredRatios(): array
    {
        if ($this->_storedRatios !== null) {
            return $this->_storedRatios;
        }

        $db = $this->db;
        $sql = 'SELECT
			metDeutRatio, crysDeutRatio
			FROM %%MARKETPLACE%%
			WHERE universeId = :universe;';

        $ratios = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
        ]);

        if (!is_array($ratios)) {
            $ratios = [];
        }

        $this->_storedRatios = [
            'metDeutRatio' => (float) ($ratios['metDeutRatio'] ?? 3.0),
            'crysDeutRatio' => (float) ($ratios['crysDeutRatio'] ?? 2.0),
        ];

        return $this->_storedRatios;
    }

    // return useful default values for when there are no trades
    private function getDefaultRatio(int $expectedrestype): float
    {
        $ratios = $this->getStoredRatios();
        if ($expectedrestype == $this->_restype_metal) {
            return $ratios['metDeutRatio'];
        }
        if ($expectedrestype == $this->_restype_crystal) {
            return $ratios['crysDeutRatio'];
        }

        return 1.0;
    }

    private function clampRatio(float $ratio): float
    {
        return min(self::RATIO_MAX, max(self::RATIO_MIN, $ratio));
    }

    private function ratioFromVolume(float $numerator, float $denominator, float $fallback): float
    {
        if ($denominator <= 0.0) {
            return $this->clampRatio($fallback);
        }

        return $this->clampRatio($numerator / $denominator);
    }

    /**
     * Stores the freshly computed metal/deuterium and crystal/deuterium market ratios for the universe once per
     * request, so the marketplace page and later push checks read the same values.
     */
    private function persistComputedRatios(): void
    {
        if ($this->_ratiosPersisted || !isset($this->_pairRatios['md'], $this->_pairRatios['cd'])) {
            return;
        }

        $this->_ratiosPersisted = true;
        $db = $this->db;
        $sql = 'UPDATE %%MARKETPLACE%%
			SET metDeutRatio = :md, crysDeutRatio = :cd
			WHERE universeId = :universe';
        $db->update($sql, [
            ':universe' => $this->universe()->current(),
            ':md' => $this->_pairRatios['md'],
            ':cd' => $this->_pairRatios['cd'],
        ]);
    }

    private function getCachedPairRatio(string $key, callable $compute): float
    {
        if (!isset($this->_pairRatios[$key])) {
            $this->_pairRatios[$key] = $compute();
            $this->persistComputedRatios();
        }

        return $this->_pairRatios[$key];
    }

    /**
     * the following functions get a 7 day trade list of all trades on a single resource pair
     */
    private function getMetSales(int $expectedrestype): array
    {
        $db = $this->db;
        $sql = 'SELECT
			`ex_resource_amount` as amount,
			seller.`resource_metal` as metal,
			seller.`owner` as seller_id,
			buyer.`owner` as buyer_id
			FROM %%TRADES%%
            JOIN %%LOG_FLEETS%% seller ON seller.`id` = `seller_fleet_id`
            JOIN %%LOG_FLEETS%% buyer ON buyer.`id` = `buyer_fleet_id`
			WHERE `transaction_type` = 0
			AND seller.`resource_crystal` = 0
			AND seller.`resource_deuterium` = 0
			AND `buy_time` >= CURDATE() - INTERVAL 7 DAY
			AND `filter_visibility` != 1
			AND `ex_resource_type` = :exprestype
			AND seller.`universe` = :universe
			AND seller.`owner` != buyer.`owner`
			ORDER BY `buy_time` DESC;';

        $trades = $db->select($sql, [
            ':exprestype' => $expectedrestype,
            ':universe' => $this->universe()->current(),
        ]);

        if (empty($trades)) {
            $trades [] = [
                'amount' => $this->getDefaultRatio($expectedrestype),
                'metal' => $this->getDefaultRatio($this->_restype_metal),
            ];
        }

        return $trades;
    }

    /**
     * Trades of the last 7 days that offered only crystal (seller's cargo had no metal/deuterium) for the given resource type.
     * @param int $expectedrestype wanted resource type of the trades (1 metal, 2 crystal, 3 deuterium).
     * @return array<int, array{amount: int|float, crystal: int|float}> wanted amount and offered crystal per trade; one default row if none.
     */
    private function getCrysSales(int $expectedrestype): array
    {
        $db = $this->db;
        $sql = 'SELECT
			`ex_resource_amount` as amount,
			seller.`resource_crystal` as crystal,
			seller.`owner` as seller_id,
			buyer.`owner` as buyer_id
			FROM %%TRADES%%
            JOIN %%LOG_FLEETS%% seller ON seller.`id` = `seller_fleet_id`
            JOIN %%LOG_FLEETS%% buyer ON buyer.`id` = `buyer_fleet_id`
			WHERE `transaction_type` = 0
			AND seller.`resource_metal` = 0
			AND seller.`resource_deuterium` = 0
			AND `buy_time` >= CURDATE() - INTERVAL 7 DAY
			AND `filter_visibility` != 1
			AND `ex_resource_type` = :exprestype
			AND seller.`universe` = :universe
			AND seller.`owner` != buyer.`owner`
			ORDER BY `buy_time` DESC;';

        $trades = $db->select($sql, [
            ':exprestype' => $expectedrestype,
            ':universe' => $this->universe()->current(),
        ]);

        if (empty($trades)) {
            $trades [] = [
                'amount' => $this->getDefaultRatio($expectedrestype),
                'crystal' => $this->getDefaultRatio($this->_restype_crystal),
            ];
        }

        return $trades;
    }

    /**
     * Trades of the last 7 days that offered only deuterium (seller's cargo had no metal/crystal) for the given resource type; counterpart of getCrysSales().
     * @param int $expectedrestype wanted resource type of the trades (1 metal, 2 crystal, 3 deuterium).
     * @return array<int, array{amount: int|float, deuterium: int|float}> wanted amount and offered deuterium per trade; one default row if none.
     */
    private function getDeutSales(int $expectedrestype): array
    {
        $db = $this->db;
        $sql = 'SELECT
			`ex_resource_amount` as amount,
			seller.`resource_deuterium` as deuterium,
			seller.`owner` as seller_id,
			buyer.`owner` as buyer_id
			FROM %%TRADES%%
            JOIN %%LOG_FLEETS%% seller ON seller.`id` = `seller_fleet_id`
            JOIN %%LOG_FLEETS%% buyer ON buyer.`id` = `buyer_fleet_id`
			WHERE `transaction_type` = 0
			AND seller.`resource_crystal` = 0
			AND seller.`resource_metal` = 0
			AND `buy_time` >= CURDATE() - INTERVAL 7 DAY
			AND `filter_visibility` != 1
			AND `ex_resource_type` = :exprestype
			AND seller.`universe` = :universe
			AND seller.`owner` != buyer.`owner`
			ORDER BY `buy_time` DESC;';

        $trades = $db->select($sql, [
            ':exprestype' => $expectedrestype,
            ':universe' => $this->universe()->current(),
        ]);

        if (empty($trades)) {
            $trades [] = [
                'amount' => $this->getDefaultRatio($expectedrestype),
                'deuterium' => $this->getDefaultRatio($this->_restype_deuterium),
            ];
        }
        return $trades;
    }

    /**
     * next 2: calculate total trade volume for a given resource pair
     */
    private function getTotalMetDeutVolume(): array
    {
        if ($this->_mdVolume != []) {
            return $this->_mdVolume;
        }

        $allTrades = [
            'metal' => 0,
            'deuterium' => 0,
        ];

        $pairs = [];
        foreach ($this->getMetSales($this->_restype_deuterium) as $trade) {
            $this->addPairVolume($pairs, $trade, (float) $trade['metal'], (float) $trade['amount']);
        }
        foreach ($this->getDeutSales($this->_restype_metal) as $trade) {
            $this->addPairVolume($pairs, $trade, (float) $trade['amount'], (float) $trade['deuterium']);
        }
        [$allTrades['metal'], $allTrades['deuterium']] = $this->cappedPairVolume($pairs);

        $this->_mdVolume = $allTrades;
        return $allTrades;
    }

    /**
     * Adds one trade to the volume of its pair of traders (unordered: A→B and B→A are one pair; the default row of an
     * empty trade list is its own pair).
     *
     * @param array<string, array{0: float, 1: float}> $pairs pair key => [resource volume, deuterium volume]
     * @param array<string, mixed> $trade
     */
    private function addPairVolume(array &$pairs, array $trade, float $resource, float $deuterium): void
    {
        $ids = [(int) ($trade['seller_id'] ?? 0), (int) ($trade['buyer_id'] ?? 0)];
        sort($ids);
        $key = implode(':', $ids);
        $current = $pairs[$key] ?? [0.0, 0.0];
        $pairs[$key] = [$current[0] + $resource, $current[1] + $deuterium];
    }

    /**
     * Sums the pair volumes with each pair's weight capped at the combined deuterium volume of all other pairs, keeping
     * the pair's own ratio: one pair of traders carries at most half of the reference, so a colluding pair cannot steer
     * the ratio with large wash trades, and a lone pair does not move it at all (the stored ratio is used). With many
     * independent traders this equals the plain volume sum. Only the default row of an empty trade list → plain sum.
     *
     * @param array<string, array{0: float, 1: float}> $pairs
     * @return array{0: float, 1: float} resource volume, deuterium volume
     */
    private function cappedPairVolume(array $pairs): array
    {
        $real = array_filter($pairs, static fn(string $key): bool => $key !== '0:0', ARRAY_FILTER_USE_KEY);
        if ($real === []) {
            return [(float) array_sum(array_column($pairs, 0)), (float) array_sum(array_column($pairs, 1))];
        }

        $total = (float) array_sum(array_column($real, 1));
        $resource = 0.0;
        $deuterium = 0.0;
        foreach ($real as [$pairResource, $pairDeuterium]) {
            if ($pairDeuterium <= 0.0) {
                continue;
            }
            $weight = min($pairDeuterium, $total - $pairDeuterium);
            $resource += $pairResource * $weight / $pairDeuterium;
            $deuterium += $weight;
        }

        return [$resource, $deuterium];
    }

    /**
     * Sums crystal and deuterium volume of both directions of crystal/deuterium trades; ratio source for crystal.
     * @return array{crystal: int|float, deuterium: int|float}
     */
    private function getTotalCrysDeutVolume(): array
    {
        if ($this->_cdVolume != []) {
            return $this->_cdVolume;
        }
        $allTrades = [
            'crystal' => 0,
            'deuterium' => 0,
        ];

        $pairs = [];
        foreach ($this->getCrysSales($this->_restype_deuterium) as $trade) {
            $this->addPairVolume($pairs, $trade, (float) $trade['crystal'], (float) $trade['amount']);
        }
        foreach ($this->getDeutSales($this->_restype_crystal) as $trade) {
            $this->addPairVolume($pairs, $trade, (float) $trade['amount'], (float) $trade['deuterium']);
        }
        [$allTrades['crystal'], $allTrades['deuterium']] = $this->cappedPairVolume($pairs);

        $this->_cdVolume = $allTrades;
        return $allTrades;
    }

    /**
     * aaand calculate the ratio based on that.
     */
    private function getMetDeutRatio(): float
    {
        return $this->getCachedPairRatio('md', function (): float {
            $trades = $this->getTotalMetDeutVolume();
            return $this->ratioFromVolume(
                (float) $trades['metal'],
                (float) $trades['deuterium'],
                $this->getStoredRatios()['metDeutRatio'],
            );
        });
    }

    private function getCrysDeutRatio(): float
    {
        return $this->getCachedPairRatio('cd', function (): float {
            $trades = $this->getTotalCrysDeutVolume();
            return $this->ratioFromVolume(
                (float) $trades['crystal'],
                (float) $trades['deuterium'],
                $this->getStoredRatios()['crysDeutRatio'],
            );
        });
    }

    /**
     * Current exchange rates relative to deuterium (persisted when recomputed).
     * @return array{md: float, cd: float} metal per deuterium, crystal per deuterium.
     */
    public function getAllTradeRatios(): array
    {
        return [
            'md' => $this->getMetDeutRatio(),
            'cd' => $this->getCrysDeutRatio(),
        ];
    }

    /**
     * Value of one unit of each resource in deuterium (deuterium = 1.0).
     * @return array<string,float> keys metal, crystal, deuterium.
     */
    public function getDeutRelativeRatios(): array
    {
        $ratios = $this->getAllTradeRatios();

        return [
            'metal' => $ratios['md'],
            'crystal' => $ratios['cd'],
            'deuterium' => 1.0,
        ];
    }

    public function getReferenceRatios(): array
    {
        return $this->normalizeReferenceRatios($this->getDeutRelativeRatios());
    }

    /**
     * Scale pair rates so the most valuable resource is 1.00.
     * A crystal/deut rate of 0.9 becomes 1.00 crystal and 1.11 deuterium.
     */
    private function normalizeReferenceRatios(array $ratios): array
    {
        $baseline = min($ratios['metal'], $ratios['crystal'], $ratios['deuterium']);
        if ($baseline <= 0.0) {
            $baseline = 1.0;
        }

        return [
            'metal' => round($ratios['metal'] / $baseline, self::REFERENCE_DECIMALS),
            'crystal' => round($ratios['crystal'] / $baseline, self::REFERENCE_DECIMALS),
            'deuterium' => round($ratios['deuterium'] / $baseline, self::REFERENCE_DECIMALS),
        ];
    }

    /**
     * @param array ['metal' => x, 'crystal' => y, 'deuterium' => z]
     * round AND int-cast because only int-cast just cuts off decimals
     */
    public function convertToDeut(array $input): int
    {
        if (!array_key_exists('metal', $input)) {
            $input['metal'] = 0;
        }
        if (!array_key_exists('crystal', $input)) {
            $input['crystal'] = 0;
        }
        if (!array_key_exists('deuterium', $input)) {
            $input['deuterium'] = 0;
        }
        return (int) ceil(($input['metal'] / $this->getMetDeutRatio())
            + ($input['crystal'] / $this->getCrysDeutRatio())
            + ($input['deuterium']));
    }

    /** @param array [901, 902, 903] mkd **/
    public function convertToDeutNumeric(array $input): int
    {
        $output = [
            'metal' => $input[901],
            'crystal' => $input[902],
            'deuterium' => $input[903],
        ];

        return $this->convertToDeut($output);
    }

    /** @param int [1, 2, 3] mkd - matching exp_res_type **/
    public function convertExpResTypeToDeut(int $expResType, float $expResAmount): int
    {
        $result = [];

        switch ($expResType) {
            case 1:
                $result['metal'] = $expResAmount;
                break;
            case 2:
                $result['crystal'] = $expResAmount;
                break;
            case 3:
                $result['deuterium'] = $expResAmount;
                break;
            default:
                throw new Exception('unexpected resource');
        }
        return $this->convertToDeut($result);
    }

    /**
     * @param $fleetArray format:
     * [
     * 		202 => 5, // kt?
     * 		203 => 4, // gt?
     * ]
     * @return int | default: MSE. $useScore = true: score
     */
    public function getFleetValue(array $fleetArray, bool $useScore = false): int
    {
        $elements = $this->elements();

        $totalCostArray = [
            901 => 0,
            902 => 0,
            903 => 0,
        ];

        foreach ($fleetArray as $ship => $count) {
            $cost = $elements->find((int) $ship)?->cost;
            if ($cost === null) {
                continue;
            }
            $totalCostArray[901] += $cost->metal * $count;
            $totalCostArray[902] += $cost->crystal * $count;
            $totalCostArray[903] += $cost->deuterium * $count;
        }

        if ($useScore) { // return value in points, not metal
            return ($totalCostArray[901] + $totalCostArray[902]) / $this->configs->get()->stat_settings;
        }

        $overallCost = $this->convertToDeutNumeric($totalCostArray);
        return $overallCost;
    }

    /** todo: player class. find or create. **/
    private function getScoreByPlayerId(int $playerid): int
    {
        $db = $this->db;
        $sql = "SELECT
					u.`username`, s.`total_points`
				FROM %%USERS%% u
				LEFT JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id` AND s.`stat_type` = 1
				WHERE u.`id` = :playerID AND u.`universe` = :universe;";
        $query = $db->selectSingle($sql, [
            ':universe'	=> $this->universe()->current(),
            ':playerID'	=> $playerid,
        ]);

        if (!$query) {
            throw new Exception('the requested player does not exist');
        }

        return $query['total_points'] ?: 0;
    }

    /**
     * @param $offer & $ask: suggest metal standard units
     * @param fleetarray optional: check if fleet size is push-relevant
     * @return bool
     */
    public function isPush(int $offer, int $ask, int $sellerId, int $buyerId, int $fleetScore = 0): bool
    {
        $sellerScore = $this->getScoreByPlayerId($sellerId);
        $buyerScore = $this->getScoreByPlayerId($buyerId);

        // special case: buyer is weaker, but becomes stronger when receiving fleet
        $specialFleetPush = ($fleetScore != 0
                && ($sellerScore > $buyerScore)
                && ($sellerScore - $fleetScore < $buyerScore + $fleetScore));

        $highestValue = ($offer > $ask) ? $offer : $ask;
        $margin = $highestValue * (1 - $this->pushTolerance());

        // seller <= buyer - too cheap?
        if ($sellerScore <= $buyerScore || $specialFleetPush) {
            return ($ask < $margin);
        } else { // too expensive?
            return ($offer < $margin);
        }
    }

    /**
     * Accepted market volatility (share of the value) that does not trigger the push check: universe setting
     * market_push_tolerance in percent (default 10).
     */
    private function pushTolerance(): float
    {
        return max(0, min(90, (float) $this->configs->get()->market_push_tolerance)) / 100;
    }

    public function isFleetPush(array $fleetArray, int $ask, int $sellerId, int $buyerId): bool
    {
        $fleetScore = $this->getFleetValue($fleetArray, true);
        $fleetValue = $this->getFleetValue($fleetArray);
        return $this->isPush($fleetValue, $ask, $sellerId, $buyerId, $fleetScore);
    }
}
