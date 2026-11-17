<?php

namespace App\Game\Bots;

use App\Game\Enum\Mission;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Expeditions the way the game rewards them. A resource find is `factor x max(min(points, cap), 200)`, limited by the free cargo room
 * (ExpeditionResourceEvent), with expedition points = (metal + crystal cost) x 5 / 1000 per ship and cap =
 * FleetFunctions::calculateMaxFactorRes() of the universe. More points than the cap earn nothing, so the bot sends large cargo ships
 * until points reach the cap (or the planet has no more), small cargos as filler, and about a tenth of the points as warships
 * against pirates and aliens. Hold time is 1 hour, 2 hours in 30 % of the cases (when the expedition technology allows it).
 *
 * Results: when a fleet comes home with more than a quarter of its value lost, the next expeditions fly with half the ships for six
 * hours. The fleet slots a profile reserves for expeditions (`expedition_slots`) are applied by BotActions::freeFleetSlots().
 * The memory lives in the personality JSON (`_expo`: `fleets` id => start ships, `until`, `mult`).
 */
class BotExpeditions
{
    use GameUtilities;

    /** Seconds the smaller fleet size stays after a bad result. */
    private const CAUTION = 21600;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotLog $botLog,
    ) {}

    /**
     * Sends an expedition from a random own planet that has cargo ships. Returns the log text, or null when none can be sent.
     *
     * @param array<string, mixed> $personality changed in place (`_expo`)
     */
    public function send(BotActions $ctx, array &$personality, int $now): ?string
    {
        $e = $this->elements();
        if (!$ctx->canSendExpedition()) {
            return null;
        }
        $ids = array_keys(array_filter($ctx->planets, static fn(array $p): bool => (int) $p[$e->column(SHIP_SMALL_CARGO)] + (int) $p[$e->column(SHIP_LARGE_CARGO)] >= 1));
        if ($ids === []) {
            return null;
        }
        shuffle($ids);
        $planet = &$ctx->planets[$ids[0]];
        $memory = $personality['_expo'] ?? [];
        $caution = ($memory['until'] ?? 0) > $now ? (float) ($memory['mult'] ?? 1.0) : 1.0;
        $fleet = $this->compose($planet, $caution);
        if ($fleet === []) {
            return null;
        }
        $hours = (int) $ctx->user[$e->column(124)] >= 2 && mt_rand(1, 100) <= 30 ? 2 : 1;
        $id = $ctx->dispatch($planet, $fleet, Mission::Expedition->value, (int) $planet['galaxy'], (int) $planet['system'], (int) $ctx->config->max_planets + 1, $hours);
        if ($id === null) {
            return null;
        }
        $personality['_expo']['fleets'][$id] = ['ships' => $fleet, 'at' => $now];
        $personality['_expo']['fleets'] = array_slice($personality['_expo']['fleets'], -6, null, true);
        $parts = [];
        foreach ($fleet as $ship => $count) {
            $parts[] = $count . ' ' . $e->column($ship);
        }

        return implode(', ', $parts) . ' (' . round($this->points($fleet)) . ' points, cap ' . $this->cap() . ', ' . $hours . ' h) from ' . $ctx->planetLabel($planet) . ($caution < 1 ? ' [careful after losses]' : '');
    }

    /** Expedition point cap of the universe (more points do not raise the find). */
    public function cap(): int
    {
        return $this->fleetFunctions()->calculateMaxFactorRes((int) $this->universe()->current());
    }

    /**
     * Fleet for an expedition from a planet: large then small cargos until the points reach the cap (at most 80-100 % of the ships, times
     * `$caution`), then warships worth about a tenth of the points. Never the probes, colony ships or satellites.
     *
     * @param array<string, mixed> $planet planet row
     * @return array<int, int> ship id => count (empty when the planet has no cargo ships)
     */
    public function compose(array $planet, float $caution = 1.0): array
    {
        $e = $this->elements();
        $cap = $this->cap();
        $share = mt_rand(80, 100) / 100 * $caution;
        $fleet = [];
        $points = 0.0;
        foreach ([SHIP_LARGE_CARGO, SHIP_SMALL_CARGO] as $id) {
            $have = (int) floor((int) $planet[$e->column($id)] * $share);
            $each = $this->points([$id => 1]);
            $n = $points >= $cap ? 0 : min($have, (int) ceil(($cap - $points) / max(0.001, $each)));
            if ($n > 0) {
                $fleet[$id] = $n;
                $points += $n * $each;
            }
        }
        if ($fleet === []) {
            return [];
        }
        foreach ([SHIP_CRUISER, SHIP_HEAVY_FIGHTER, SHIP_LIGHT_FIGHTER] as $id) {
            $each = $this->points([$id => 1]);
            $n = min((int) floor((int) $planet[$e->column($id)] * $share), (int) floor(0.1 * $points / max(0.001, $each)));
            if ($n > 0) {
                $fleet[$id] = $n;
                break;
            }
        }

        return $fleet;
    }

    /**
     * Expedition points of ships.
     *
     * @param array<int, int> $fleet ship id => count
     */
    public function points(array $fleet): float
    {
        $e = $this->elements();
        $sum = 0.0;
        foreach ($fleet as $id => $count) {
            $sum += $count * ($e->cost($id)->metal + $e->cost($id)->crystal) * 5 / 1000;
        }

        return $sum;
    }

    /**
     * Looks at the expeditions that came home: more than a quarter of the fleet's value lost makes the bot careful for six hours
     * (half the ships). A fleet that is back (status return) is compared with what it started with, then forgotten.
     *
     * @param array<string, mixed> $personality changed in place
     * @param array<string, mixed> $bot
     */
    public function settle(array $bot, array &$personality, int $now): void
    {
        foreach ($personality['_expo']['fleets'] ?? [] as $id => $entry) {
            $row = $this->db->selectSingle('SELECT status, ships FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
            if (!empty($row) && (int) $row['status'] !== FLEET_RETURN) {
                continue;
            }
            unset($personality['_expo']['fleets'][$id]);
            if (empty($row)) {
                continue;
            }
            $now_ = $this->fleetFunctions()->unserialize((string) $row['ships']);
            $startValue = $this->points($entry['ships']);
            $lost = $startValue - $this->points($now_);
            if ($startValue > 0 && $lost / $startValue > 0.25) {
                $personality['_expo']['until'] = $now + self::CAUTION;
                $personality['_expo']['mult'] = 0.5;
                $this->botLog->log((int) $bot['user_id'], (int) $bot['universe'], 'expo_result', 'lost ' . round(100 * $lost / $startValue) . ' % of the fleet, next expeditions with half the ships for 6 h');
            }
        }
    }
}
