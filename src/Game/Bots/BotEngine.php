<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\FlyingFleetHandler;
use App\Game\Core\GameData;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Drives the bots: decides when a bot is "online", runs its session ticks and
 * lets the brain pick actions. All gameplay decisions are local (no LLM).
 */
class BotEngine
{
    use GameUtilities;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotProfiles $profiles,
        private readonly BotBrain $brain,
        private readonly BotSocial $social,
        private readonly BotPersona $persona,
        private readonly BotLog $botLog,
        private readonly FlyingFleetHandler $fleetHandler,
    ) {}

    /** Writes an entry to the bot activity log (see BotLog). */
    public function log(int $userId, int $universe, string $action, string $detail = ''): void
    {
        $this->botLog->log($userId, $universe, $action, $detail);
    }

    /**
     * Makes $universe the current universe of this process: fleet functions, module checks and
     * the unit data (game vars) follow Universe::current(), which is the game state's universeCurrent.
     */
    public function useUniverse(int $universe): void
    {
        $current = $this->state->universeCurrent;
        if ((int) $current === $universe && !empty($this->state->resource)) {
            return;
        }

        $this->state->universeCurrent = $universe;
        (new GameData($this->db, $this->configs, $this->state))->load();
    }

    /** Random integer in the inclusive range [min, max] given as a two-element profile setting (order-insensitive, default 1). */
    private function roll(array $range): int
    {
        $min = (int) ($range[0] ?? 1);
        $max = (int) ($range[1] ?? $min);

        return random_int(min($min, $max), max($min, $max));
    }

    /**
     * Processes up to `$limit` bots of the universe whose `next_tick` is due (oldest first), after running the due
     * fleet events of bot fleets. Does nothing unless the universe is open (STATUS_OPEN). An exception in one bot is
     * logged ('error' in the bot log), that bot is postponed by 15 minutes, and the loop continues. Roughly every 200th
     * call purges bot log entries older than 14 days.
     *
     * @return int number of processed bots
     */
    public function tick(int $universe, int $limit): int
    {
        $db = $this->db;
        $now = time();

        $config = $this->configs->get($universe);
        if ($config->uni_status != STATUS_OPEN) {
            return 0;
        }

        $this->useUniverse($universe);
        $this->processFleetEvents($universe);

        $bots = $db->select(
            'SELECT * FROM %%BOT_PLAYERS%% WHERE universe = :universe AND next_tick <= :now ORDER BY next_tick ASC LIMIT :limit;',
            [':universe' => $universe, ':now' => $now, ':limit' => $limit],
        );

        $profiles = [];
        $processed = 0;
        foreach ($bots as $bot) {
            $processed++;
            try {
                if (!isset($profiles[$bot['profile_id']])) {
                    $profiles[$bot['profile_id']] = $this->profiles->load((int) $bot['profile_id']);
                }
                $profile = $profiles[$bot['profile_id']];
                if ($profile === null) {
                    throw new \Exception('profile ' . $bot['profile_id'] . ' missing');
                }
                $this->processBot($bot, $profile, $now);
            } catch (Throwable $e) {
                try {
                    $db->rollback();
                } catch (Throwable $ignored) {
                }
                try {
                    $this->log((int) $bot['user_id'], $universe, 'error', get_class($e) . ': ' . $e->getMessage());
                    $db->update(
                        'UPDATE %%BOT_PLAYERS%% SET next_tick = :next WHERE user_id = :id;',
                        [':next' => $now + 900, ':id' => $bot['user_id']],
                    );
                } catch (Throwable $ignored) {
                }
                error_log('BotEngine bot ' . $bot['user_id'] . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
            }
        }

        if (random_int(1, 200) === 1) {
            $db->delete('DELETE FROM %%BOT_LOG%% WHERE `time` < :t;', [':t' => $now - 14 * 86400]);
        }

        return $processed;
    }

    /**
     * Bot fleets (expeditions, colony ships) are normally resolved when a human loads a page.
     * Do the same claim + run here (as GameBootstrap does) so they also work on quiet servers.
     */
    private function processFleetEvents(int $universe): void
    {
        $db = $this->db;
        $due = (int) $db->selectSingle(
            'SELECT COUNT(*) as count FROM %%FLEETS_EVENT%% fe
            INNER JOIN %%FLEETS%% f ON f.id = fe.fleetID
            INNER JOIN %%BOT_PLAYERS%% b ON b.user_id = f.owner
            WHERE fe.`lock` IS NULL AND fe.`time` <= :time AND fe.universe = :universe;',
            [':time' => time(), ':universe' => $universe],
            'count',
        );
        if ($due === 0) {
            return;
        }

        $token = getRandomString();
        $db->startTransaction();
        try {
            $db->update(
                'UPDATE %%FLEETS_EVENT%% SET `lock` = :token
                WHERE `lock` IS NULL AND `time` <= :time AND `universe` = :universe;',
                [':token' => $token, ':time' => time(), ':universe' => $universe],
            );
            $claimed = $db->rowCount();
            $db->commit();
        } catch (Throwable $e) {
            $db->rollback();
            throw $e;
        }

        if ($claimed > 0) {
            $db->startTransaction();
            try {
                $this->fleetHandler->setToken($token);
                $this->fleetHandler->run();
                $db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE `lock` = :token;', [':token' => $token]);
                $db->commit();
            } catch (Throwable $e) {
                $db->rollback();
                $db->update('UPDATE %%FLEETS_EVENT%% SET `lock` = NULL WHERE `lock` = :token;', [':token' => $token]);
                throw $e;
            }
        }
    }

    /**
     * One tick of one bot. Skill grows with the bot's age (`skill_start` + days * `skill_gain_per_day`, capped at
     * `skill_max`). Outside a session the bot first rolls whether it comes online this tick (rollActive(), hourly
     * activity profile); on success a session starts: length from `session_minutes`, planned action count from
     * `actions_per_session`, plus rolled special actions (expedition, colonize; chances in percent). While online it
     * bumps `onlinetime`, runs 1-3 actions in one DB transaction (rolled back on error), then the social behaviour
     * (isolated: its errors are only logged). Finally it schedules the next tick (60-240 s later inside a session,
     * `check_interval` minutes after the session ended) and persists skill, personality and session state.
     *
     * @param array<string, mixed> $bot     row of {prefix}bot_players
     * @param array<string, mixed> $profile profile with its `settings` (see BotProfiles)
     * @param int                  $now     unix timestamp of this tick
     */
    private function processBot(array $bot, array $profile, int $now): void
    {
        $db = $this->db;
        $universe = (int) $bot['universe'];
        $userId = (int) $bot['user_id'];
        $s = $profile['settings'];
        $personality = json_decode((string) $bot['personality'], true);
        if (!is_array($personality)) {
            $personality = [];
        }

        $daysOld = max(0, ($now - (int) $bot['created_at']) / 86400);
        $skill = min((float) $s['skill_max'], (float) $s['skill_start'] + $daysOld * (float) $s['skill_gain_per_day']);

        $session = $personality['_s'] ?? ['left' => 0];
        $inSession = (int) $bot['session_until'] > $now;
        $sessionUntil = (int) $bot['session_until'];

        if (!$inSession) {
            if (!$this->rollActive($s, $now, (int) $bot['created_at'])) {
                $this->save($bot, $skill, $personality, $now + $this->roll($s['check_interval']) * 60, $sessionUntil);

                return;
            }

            $sessionUntil = $now + $this->roll($s['session_minutes']) * 60;
            $mult = (float) ($personality['expo_mult'] ?? 1.0);
            $session = [
                'left' => max(1, $this->roll($s['actions_per_session'])),
                'expo' => mt_rand(1, 10000) <= (float) $s['expedition_chance'] * $mult * 100,
                'colo' => mt_rand(1, 10000) <= (float) $s['colonize_chance'] * 100,
                'social' => true,
            ];
            $db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => $now, ':id' => $userId]);
            $this->log($userId, $universe, 'login', 'session ' . round(($sessionUntil - $now) / 60) . ' min, ' . $session['left'] . ' actions planned');
        } else {
            $db->update('UPDATE %%USERS%% SET onlinetime = :t WHERE id = :id;', [':t' => $now, ':id' => $userId]);
        }

        // --- gameplay inside one transaction ---
        $user = null;
        $botRow = $bot;
        $db->startTransaction();
        try {
            $ctx = new BotActions($universe, $this->db, $this->configs, $this->state, $this->persona);
            if (!$ctx->load($userId)) {
                $db->rollback();
                throw new \Exception('user row missing');
            }

            $persona = !empty($bot['persona']) ? json_decode((string) $bot['persona'], true) : null;
            if (is_array($persona)) {
                $ctx->renameDefaultColonies($bot, $persona);
            }

            $actions = min((int) $session['left'], random_int(1, 3));
            for ($i = 0; $i < $actions; $i++) {
                $this->step($ctx, $s, $personality, $skill, $session, $userId, $universe);
                $session['left']--;
            }
            $user = $ctx->user;
            $db->commit();
        } catch (Throwable $e) {
            try {
                $db->rollback();
            } catch (Throwable $ignored) {
            }
            throw $e;
        }

        // --- social behaviour, isolated from gameplay errors ---
        if ($user !== null) {
            try {
                $this->social->act($botRow, $user, $profile['settings']);
            } catch (Throwable $e) {
                $this->log($userId, $universe, 'social_error', get_class($e) . ': ' . $e->getMessage());
            }
        }

        $next = $now + random_int(60, 240);
        $personality['_s'] = $session;
        if ($session['left'] <= 0 || $next >= $sessionUntil) {
            $personality['_s']['left'] = 0;
            $this->save($bot, $skill, $personality, $now + $this->roll($s['check_interval']) * 60, $now);
            $this->log($userId, $universe, 'logout', 'session finished');
        } else {
            $this->save($bot, $skill, $personality, $next, $sessionUntil);
        }
    }

    /**
     * One action: first the special actions rolled at session start (expedition, then colonization; each is tried only
     * once and ends the step when it succeeded), otherwise BotBrain picks a build, research, ship, defense, mistake or
     * wait decision and BotActions executes it. A rejected decision is logged as 'wait'.
     *
     * @param array<string, mixed> $session session state (`left` actions, `expo`/`colo` flags), updated in place
     */
    private function step(BotActions $ctx, array $s, array $personality, float $skill, array &$session, int $userId, int $universe): void
    {
        // optional special actions rolled at session start
        if (!empty($session['expo'])) {
            $session['expo'] = false;
            $detail = $ctx->expedition();
            if ($detail !== null) {
                $this->done($userId, $universe, 'expedition', $detail);

                return;
            }
        }
        if (!empty($session['colo'])) {
            $session['colo'] = false;
            $detail = $ctx->colonize();
            if ($detail !== null) {
                $this->done($userId, $universe, 'colonize', $detail);

                return;
            }
        }

        $decision = $this->brain->choose($ctx, $s, $personality, $skill);
        if ($decision['kind'] === 'wait') {
            $this->log($userId, $universe, 'wait', (string) ($decision['note'] ?? ''));

            return;
        }

        $detail = match ($decision['type']) {
            'build' => $ctx->build($decision['planet'], $decision['element']),
            'research' => $ctx->research($decision['planet'], $decision['element']),
            'ship', 'defense' => $ctx->units($decision['planet'], $decision['element'], $decision['count']),
            default => null,
        };

        if ($detail === null) {
            $this->log($userId, $universe, 'wait', 'rejected ' . $decision['label']);

            return;
        }

        $action = $decision['kind'] === 'mistake' ? 'mistake' : $decision['type'];
        $this->done($userId, $universe, $action, ($decision['kind'] === 'mistake' ? $decision['type'] . ': ' : '') . $detail);
    }

    /** Logs a successful action and counts it in `actions_total` / `last_action_at` of the bot. */
    private function done(int $userId, int $universe, string $action, string $detail): void
    {
        $this->log($userId, $universe, $action, $detail);
        $this->db->update(
            'UPDATE %%BOT_PLAYERS%% SET actions_total = actions_total + 1, last_action_at = :t WHERE user_id = :id;',
            [':t' => time(), ':id' => $userId],
        );
    }

    /**
     * Whether the bot comes online now: `activity` holds 24 probabilities (0-1) by hour of day; after
     * `pattern_shift_days` the daily rhythm moves by `pattern_shift_hours`, so bots do not keep the same schedule forever.
     */
    private function rollActive(array $s, int $now, int $createdAt): bool
    {
        $activity = array_values((array) $s['activity']);
        $hour = (int) date('G', $now);
        $shiftDays = (int) $s['pattern_shift_days'];
        $shift = 0;
        if ($shiftDays > 0) {
            $shift = (int) $s['pattern_shift_hours'] * (int) floor(max(0, $now - $createdAt) / 86400 / $shiftDays);
        }
        $index = (($hour - $shift) % 24 + 24) % 24;

        return mt_rand(1, 10000) <= (float) $activity[$index] * 10000;
    }

    /** Persists skill, personality JSON, `next_tick` and `session_until` of the bot (unix timestamps). */
    private function save(array $bot, float $skill, array $personality, int $nextTick, int $sessionUntil): void
    {
        $this->db->update(
            'UPDATE %%BOT_PLAYERS%% SET skill = :skill, personality = :personality, next_tick = :next, session_until = :until WHERE user_id = :id;',
            [
                ':skill' => $skill,
                ':personality' => json_encode($personality),
                ':next' => $nextTick,
                ':until' => $sessionUntil,
                ':id' => $bot['user_id'],
            ],
        );
    }
}
