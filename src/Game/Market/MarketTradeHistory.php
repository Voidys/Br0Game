<?php

namespace App\Game\Market;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * The two "last trades" tables of the marketplace page (resource trades and fleet trades of the current universe,
 * newest 40 each), prepared for the template. Read only.
 */
class MarketTradeHistory
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private ?MarketRules $rules = null,
    ) {
        $this->rules ??= new MarketRules($db, $configs, $state);
    }

    /**
     * Completed resource trades (transaction type 0): time, offered resource/amount and what was paid.
     *
     * @return list<array<string, mixed>> rows with offer_*, cost_* and formatted `time`
     */
    public function resourceTrades(): array
    {
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $sql = 'SELECT
			UNIX_TIMESTAMP(`buy_time`) as time_unix,
			`ex_resource_type` as res_type,
			`ex_resource_amount` as amount,
			`resource_type`,
			`resource_amount`,
			seller.`resource_metal` as metal,
			seller.`resource_crystal` as crystal,
			seller.`resource_deuterium` as deuterium
			FROM %%TRADES%%
            JOIN %%LOG_FLEETS%% seller ON seller.`id` = `seller_fleet_id`
            JOIN %%LOG_FLEETS%% buyer ON buyer.`id` = `buyer_fleet_id`
			WHERE `transaction_type` = 0 AND seller.`universe` = :universe ORDER BY `time_unix` DESC LIMIT 40;';
        $trades = $db->select($sql, [
            ':universe' => $this->universe()->current(),
        ]);
        $resourceNames = [
            1 => $LNG['tech'][901],
            2 => $LNG['tech'][902],
            3 => $LNG['tech'][903],
        ];
        foreach ($trades as &$trade) {
            $this->assignTime($trade);
            $offer = $this->rules->resolveOfferedResource(
                (int) $trade['resource_type'],
                $trade['resource_amount'],
                $trade['metal'],
                $trade['crystal'],
                $trade['deuterium'],
                $resourceNames,
            );
            $costType = (int) $trade['res_type'];
            $trade['offer_type'] = $offer['id'];
            $trade['offer_amount'] = $offer['amount'];
            $trade['offer_name'] = $offer['name'];
            $trade['cost_type'] = $costType;
            $trade['cost_amount'] = $trade['amount'];
            $trade['cost_name'] = $resourceNames[$costType] ?? ' ';
        }
        unset($trade);

        return $trades;
    }

    /**
     * Completed fleet trades (transaction type 1): time, the sold fleet as text and what was paid.
     *
     * @return list<array<string, mixed>> rows with fleet_str, cost_* and formatted `time`
     */
    public function fleetTrades(): array
    {
        $LNG = & $this->state->LNG;
        $db = $this->db;
        $sql = 'SELECT
			seller.`ships` as fleet,
			UNIX_TIMESTAMP(`buy_time`) as time_unix,
			`ex_resource_type` as res_type,
			`ex_resource_amount` as amount
			FROM %%TRADES%%
            JOIN %%LOG_FLEETS%% seller ON seller.`id` = `seller_fleet_id`
            JOIN %%LOG_FLEETS%% buyer ON buyer.`id` = `buyer_fleet_id`
			WHERE `transaction_type` = 1 AND seller.`universe` = :universe ORDER BY `time_unix` DESC LIMIT 40;';

        $trades = $db->select($sql, [
            ':universe' => $this->universe()->current(),
        ]);
        $resourceNames = [
            1 => $LNG['tech'][901],
            2 => $LNG['tech'][902],
            3 => $LNG['tech'][903],
        ];
        for ($i = 0; $i < count($trades); $i++) {
            $this->assignTime($trades[$i]);
            $fleet =  $this->fleetFunctions()->unserialize($trades[$i]['fleet']);
            $fleet_str = '';
            foreach ($fleet as $name => $amount) {
                $fleet_str .= $LNG['shortNames'][$name] . ' x' . $amount . "\n";
            }
            $costType = (int) $trades[$i]['res_type'];
            $trades[$i]['fleet_str'] = $fleet_str;
            $trades[$i]['cost_type'] = $costType;
            $trades[$i]['cost_amount'] = $trades[$i]['amount'];
            $trades[$i]['cost_name'] = $resourceNames[$costType] ?? ' ';
        }
        return $trades;
    }

    /**
     * Adds `time` (formatted in the player's timezone, '' without a buy time) and an int `time_unix` to a trade row.
     *
     * @param array<string, mixed> $trade
     */
    private function assignTime(array &$trade): void
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $timestamp = (int) ($trade['time_unix'] ?? 0);
        $trade['time_unix'] = $timestamp;
        $trade['time'] = $timestamp > 0
            ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], $timestamp, $USER['timezone'])
            : '';
    }
}
