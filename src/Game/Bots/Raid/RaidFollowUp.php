<?php

namespace App\Game\Bots\Raid;

use App\Game\Bots\BotActions;
use App\Game\Bots\BotLog;
use App\Game\Bots\BotPersona;
use App\Game\Bots\BotSocialMessaging;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * What happens to an attack after it left: the pre-impact re-spy with the keep-or-recall decision, and the settling of the hit
 * (loot logged, farm list, raid streak start, taunt).
 *
 * Re-spy timing (a task is up to CRON_GAP late): the report is meant to be read 150-240 s before impact. The probe is sent
 * early enough that, even with a late send and a late reading, the decision still falls before that point; `recheck_at` = impact -
 * margin - SLACK - probe flight time. A flight too short for that is not re-spied.
 */
class RaidFollowUp
{
    use GameUtilities;

    /** Longest wait between two runs of the bot cron job in seconds (it runs every minute; a late run or a busy tick adds half a minute). */
    private const CRON_GAP = 90;

    /** Seconds of safety between the planned report reading and the impact: a late send plus a late reading (two cron gaps) and the probe's arrival. */
    private const SLACK = 2 * self::CRON_GAP + 10;

    /** A hit worth a raid streak is at least this share of RaidPlanner::MIN_LOOT. */
    private const STREAK_LOOT = 0.5;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly RaidSpyReports $reports,
        private readonly RaidPlanner $planner,
        private readonly RaidDispatcher $dispatcher,
        private readonly BotLog $botLog,
        private readonly BotSocialMessaging $messaging,
    ) {}

    /**
     * Rolls whether this attack gets a pre-impact re-spy (`recall_check_chance`) and if so when the probe must leave.
     *
     * @param array<string, mixed> $attack the memory entry of the attack (`impact`, `origin`, `planet`, `target`), `state`/`recheck_at` are set
     * @param array<string, mixed> $s profile settings
     * @return string log text about the schedule
     */
    public function schedule(BotActions $ctx, array &$attack, array $s, int $probes, int $now): string
    {
        $attack['state'] = 'none';
        $attack['n'] = max(2, $probes);
        $attack['settle_at'] = (int) $attack['impact'] + 15;
        if (mt_rand(1, 100) > (int) $s['recall_check_chance']) {
            return 'no re-spy';
        }
        $planet = $ctx->planets[$attack['origin']];
        $t = $attack['target'];
        $flight = $this->planner->flight($ctx, $planet, [SHIP_PROBE => $attack['n']], (int) $t['galaxy'], (int) $t['system'], (int) $t['planet']);
        for ($margin = mt_rand(150, 240); $margin >= 150; $margin -= 30) {
            $at = (int) $attack['impact'] - $margin - self::SLACK - $flight['duration'];
            if ($at >= $now + 30) {
                $attack['state'] = 'scheduled';
                $attack['recheck_at'] = $at;

                return 're-spy at ' . date('H:i:s', $at) . ' (report due ' . date('H:i:s', $at + $flight['duration'] + 10) . ')';
            }
        }

        return 'flight too short to re-spy';
    }

    /**
     * Runs the re-spy tasks that are due: sends the probe, reads the new report, keeps or recalls the attack.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $mem raid memory, changed in place
     * @return int number of actions taken (probe sent, fleet recalled)
     */
    public function recheck(BotActions $ctx, array $bot, array $s, array &$mem, int $now): int
    {
        $actions = 0;
        foreach ($mem['attacks'] as $fleetId => &$attack) {
            $state = $attack['state'] ?? 'none';
            if ($state === 'scheduled' && $now >= (int) $attack['recheck_at']) {
                $actions += $this->sendProbe($ctx, $bot, $attack, $now);
            } elseif ($state === 'probing' && $now >= (int) $attack['read_at']) {
                $actions += $this->readAndDecide($ctx, $bot, (int) $fleetId, $attack, $mem, $now);
            }
        }
        unset($attack);

        return $actions;
    }

    /**
     * Sends the re-spy probes of an attack (from the planet the attack left, else any planet with probes), unless the fleet is gone,
     * not outbound any more, or the probes would arrive too late.
     *
     * @param array<string, mixed> $attack memory entry, `state` moves to probing or done
     * @return int 1 when probes were sent
     */
    private function sendProbe(BotActions $ctx, array $bot, array &$attack, int $now): int
    {
        $botId = (int) $bot['user_id'];
        $uni = (int) $bot['universe'];
        $fleet = $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $attack['id']]);
        $attack['state'] = 'done';
        if (empty($fleet) || (int) $fleet['status'] !== FLEET_OUTWARD) {
            return 0;
        }
        $resource = $this->state->resource;
        $origin = $this->probePlanet($ctx, (int) $attack['origin'], (int) $attack['n']);
        if ($origin === null) {
            $this->botLog->log($botId, $uni, 'raid_keep', 'no re-spy: no probes at home, keeping the attack on ' . $attack['name']);

            return 0;
        }
        $n = min((int) $attack['n'], (int) $ctx->planets[$origin][$resource[SHIP_PROBE]]);
        $t = $attack['target'];
        $flight = $this->planner->flight($ctx, $ctx->planets[$origin], [SHIP_PROBE => $n], (int) $t['galaxy'], (int) $t['system'], (int) $t['planet']);
        if ($now + $flight['duration'] + 10 > (int) $attack['impact'] - 90) {
            $this->botLog->log($botId, $uni, 'raid_keep', 'no re-spy: probes would arrive too late, keeping the attack on ' . $attack['name']);

            return 0;
        }
        $sent = $this->dispatcher->send($ctx, $ctx->planets[$origin], [SHIP_PROBE => $n], MISSION_SPY, (int) $attack['planet'], (int) $attack['user']);
        if (!$sent['ok']) {
            $this->botLog->log($botId, $uni, 'raid_keep', 'no re-spy (' . $sent['reason'] . '), keeping the attack on ' . $attack['name']);

            return 0;
        }
        $attack['state'] = 'probing';
        $attack['probe_sent'] = $now;
        $attack['probe_id'] = $sent['id'];
        $attack['read_at'] = $sent['arrival'] + 10;
        $this->botLog->log($botId, $uni, 'raid_recheck', $n . ' probes to ' . $attack['name'] . ' [' . $attack['coords'] . '], report ' . date('H:i:s', $sent['arrival']) . ', impact ' . date('H:i:s', (int) $attack['impact']));

        return 1;
    }

    /**
     * Planet that has probes for a re-spy: the preferred planet when it has at least one, else the one with the most.
     *
     * @return int|null planet id
     */
    private function probePlanet(BotActions $ctx, int $preferred, int $wanted): ?int
    {
        $resource = $this->state->resource;
        $have = static fn(array $p): int => (int) $p[$resource[SHIP_PROBE]];
        if (isset($ctx->planets[$preferred]) && $have($ctx->planets[$preferred]) >= 1) {
            return $preferred;
        }
        $best = null;
        foreach ($ctx->planets as $id => $planet) {
            if ($have($planet) >= 1 && ($best === null || $have($planet) > $have($ctx->planets[$best]))) {
                $best = $id;
            }
        }

        return $best;
    }

    /**
     * Reads the re-spy report and keeps or recalls the attack. Without a report: the attack is kept when the probes are still on their
     * way (read again soon, until shortly before impact); recalled when the probes are gone and said nothing (the target defends itself).
     *
     * @param array<string, mixed> $attack memory entry
     * @param array<string, mixed> $mem raid memory
     * @return int 1 when the fleet was recalled
     */
    private function readAndDecide(BotActions $ctx, array $bot, int $fleetId, array &$attack, array &$mem, int $now): int
    {
        $botId = (int) $bot['user_id'];
        $uni = (int) $bot['universe'];
        $report = $this->reports->latest($botId, (int) $attack['planet'], $uni, (int) $attack['probe_sent']);
        if ($report === null) {
            $probesFlying = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE id = :id;', [':id' => $attack['probe_id']], 'c') > 0;
            if ($probesFlying && $now < (int) $attack['impact'] - 40) {
                $attack['read_at'] = $now + 20;

                return 0;
            }
            $attack['state'] = 'done';
            if (!$probesFlying) {
                return $this->recall($ctx, $botId, $uni, $fleetId, $attack, $mem, 'no report from the re-spy, probes lost', $now);
            }
            $this->botLog->log($botId, $uni, 'raid_keep', 'no report in time, keeping the attack on ' . $attack['name']);

            return 0;
        }
        $attack['state'] = 'done';
        $fleet = $this->db->selectSingle('SELECT ships, status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
        if (empty($fleet) || (int) $fleet['status'] !== FLEET_OUTWARD) {
            return 0;
        }
        $verdict = $this->planner->assess($ctx, $this->fleetFunctions()->unserialize((string) $fleet['ships']), $report, $attack['plan']);
        $why = $this->recallReason($verdict, $attack['plan']);
        $numbers = $verdict === null
            ? 'report shows no fleet/defense'
            : 'loot ' . round($verdict['loot']) . ' (planned ' . round($attack['plan']['loot']) . '), ratio ' . round($verdict['ratio'], 1) . ', losses ' . round($verdict['losses']) . ', ' . ($verdict['win'] ? 'win' : 'no win');
        if ($why === null) {
            $this->botLog->log($botId, $uni, 'raid_keep', 'attack on ' . $attack['name'] . ' [' . $attack['coords'] . '] stays: ' . $numbers);

            return 0;
        }

        return $this->recall($ctx, $botId, $uni, $fleetId, $attack, $mem, $why . ' (' . $numbers . ')', $now);
    }

    /**
     * Why an attack should be recalled after the new report, null when it still looks good: no fleet/defense shown any more, no
     * win any more, or the profit ratio fell under half the profile's threshold (fuel is spent either way, so there is hysteresis).
     *
     * @param array{win: bool, loot: float, losses: float, ratio: float}|null $verdict RaidPlanner::assess()
     * @param array<string, mixed> $plan the launch plan (`min_ratio`)
     */
    public function recallReason(?array $verdict, array $plan): ?string
    {
        if ($verdict === null) {
            return 'target hides its defense now';
        }
        if (!$verdict['win']) {
            return 'target got stronger, the fight is not safe';
        }
        if ($verdict['loot'] < RaidPlanner::MIN_LOOT * 0.5 || $verdict['ratio'] < max(1.0, (float) ($plan['min_ratio'] ?? 1.0) * 0.5)) {
            return 'resources dropped, not worth it any more';
        }

        return null;
    }

    /**
     * Recalls the fleet through the game's recall, logs why and leaves the target alone for a while.
     *
     * @param array<string, mixed> $attack memory entry
     * @param array<string, mixed> $mem raid memory
     * @return int 1 when recalled
     */
    private function recall(BotActions $ctx, int $botId, int $uni, int $fleetId, array $attack, array &$mem, string $reason, int $now): int
    {
        $ok = $this->dispatcher->recall($ctx, $fleetId);
        $this->botLog->log($botId, $uni, 'raid_recall', ($ok ? 'recalled ' : 'recall refused for ') . 'attack on ' . $attack['name'] . ' [' . $attack['coords'] . ']: ' . $reason);
        $mem['attacks'][$fleetId]['state'] = 'settled';
        $mem['targets'][(int) $attack['planet']]['skip_until'] = $now + RaidTargetFinder::COOLDOWN;

        return $ok ? 1 : 0;
    }

    /**
     * Settles attacks whose impact has passed: logs the loot, notes the target in the farm list, starts or extends the raid streak after
     * a profitable hit, sometimes taunts a human target.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $mem raid memory, changed in place
     * @param array<string, mixed> $personality changed in place (farm list)
     */
    public function settle(BotActions $ctx, array $bot, array $s, array &$mem, array &$personality, int $now): void
    {
        foreach ($mem['attacks'] as $fleetId => $attack) {
            if (($attack['state'] ?? '') === 'settled' || $now < (int) ($attack['settle_at'] ?? PHP_INT_MAX)) {
                continue;
            }
            $fleet = $this->db->selectSingle('SELECT status FROM %%FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
            if (!empty($fleet) && (int) $fleet['status'] === FLEET_OUTWARD) {
                $mem['attacks'][$fleetId]['settle_at'] = $now + 30; // the arrival event is not processed yet

                continue;
            }
            $mem['attacks'][$fleetId]['state'] = 'settled';
            $log = $this->db->selectSingle('SELECT hasCanceled, gained_metal, gained_crystal, gained_deuterium FROM %%LOG_FLEETS%% WHERE id = :id;', [':id' => $fleetId]);
            if (empty($log) || !empty($log['hasCanceled'])) {
                continue;
            }
            $this->result($ctx, $bot, $s, $mem, $personality, $attack, $log, $now);
        }
    }

    /**
     * The outcome of one hit.
     *
     * @param array<string, mixed> $attack memory entry
     * @param array<string, mixed> $log fleet log row (gained resources)
     */
    private function result(BotActions $ctx, array $bot, array $s, array &$mem, array &$personality, array $attack, array $log, int $now): void
    {
        $botId = (int) $bot['user_id'];
        $uni = (int) $bot['universe'];
        $gained = [RESOURCE_METAL => (float) $log['gained_metal'], RESOURCE_CRYSTAL => (float) $log['gained_crystal'], RESOURCE_DEUT => (float) $log['gained_deuterium']];
        $value = 0.0;
        foreach ($gained as $id => $amount) {
            $value += $amount * RaidPlanner::VALUE[$id];
        }
        $label = $attack['name'] . ' [' . $attack['coords'] . ']';
        $mem['targets'][(int) $attack['planet']]['last_hit'] = $now;
        if ($value <= 0) {
            $this->botLog->log($botId, $uni, 'raid_result', 'no loot from ' . $label . ' (fleet lost or target empty)');
            $mem['targets'][(int) $attack['planet']]['skip_until'] = $now + RaidTargetFinder::COOLDOWN;

            return;
        }
        $this->botLog->log($botId, $uni, 'raid_result', 'hit ' . $label . ': +' . round($gained[RESOURCE_METAL]) . ' metal, +' . round($gained[RESOURCE_CRYSTAL]) . ' crystal, +' . round($gained[RESOURCE_DEUT]) . ' deuterium (value ' . round($value) . ', planned ' . round((float) $attack['plan']['loot']) . ')');
        RaidMemory::farm($personality, (int) $attack['planet'], (int) $attack['user'], (string) $attack['name'], $value, $now);
        if ($value >= RaidPlanner::MIN_LOOT * self::STREAK_LOOT) {
            $this->streak($ctx, $botId, $uni, $s, $mem, $label, $now);
        }
        if (!$attack['is_bot'] && mt_rand(1, 100) <= 15) {
            $this->taunt($bot, $s, $attack, $botId, $uni);
        }
    }

    /**
     * Starts the raid streak after a profitable hit (length from `relentless_minutes`, none when that is 0) or counts the hit in a running one.
     *
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $mem raid memory
     */
    private function streak(BotActions $ctx, int $botId, int $uni, array $s, array &$mem, string $label, int $now): void
    {
        if (!empty($mem['streak']) && (int) $mem['streak']['until'] > $now) {
            $mem['streak']['hits']++;
            $mem['streak']['idle'] = 0;

            return;
        }
        [$min, $max] = $s['relentless_minutes'];
        if ((int) $max <= 0) {
            return;
        }
        $minutes = random_int((int) $min, (int) $max);
        $mem['streak'] = ['start' => $now, 'until' => $now + $minutes * 60, 'hits' => 1, 'idle' => 0];
        $this->botLog->log($botId, $uni, 'raid_streak', 'start: ' . $minutes . ' min of raiding after hitting ' . $label);
    }

    /**
     * Sends the taunt to a raided human.
     *
     * @param array<string, mixed> $bot bot row
     * @param array<string, mixed> $s profile settings
     * @param array<string, mixed> $attack memory entry
     */
    private function taunt(array $bot, array $s, array $attack, int $botId, int $uni): void
    {
        $me = $this->db->selectSingle('SELECT id, username, galaxy, `system`, planet FROM %%USERS%% WHERE id = :id;', [':id' => $botId]);
        $lang = (string) $this->db->selectSingle('SELECT lang FROM %%USERS_SETTINGS%% WHERE user_id = :id;', [':id' => $attack['user']], 'lang');
        if (empty($me)) {
            return;
        }
        $ctx = ['bot' => $botId, 'uni' => $uni, 'me' => $me, 'persona' => BotPersona::decode($bot['persona'] ?? null), 'profile' => $s];
        $sent = $this->messaging->taunt($ctx, (int) $attack['user'], $lang);
        $this->botLog->log($botId, $uni, 'raid_taunt', ($sent ? 'taunted ' : 'no taunt for ') . $attack['name']);
    }
}
