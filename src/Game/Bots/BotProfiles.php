<?php

namespace App\Game\Bots;

use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Bot behaviour profiles ({prefix}bot_profiles): how often and how long a bot plays, how skilled it is, what it
 * builds and how social it is. Settings are stored as JSON and normalized (missing keys filled, values clamped) on
 * every read and write. Five default profiles can be restored; BotEngine reads them, the admin page edits them.
 */
class BotProfiles
{
    public function __construct(
        private readonly Database $db,
        private readonly Singleton $state,
    ) {}

    /**
     * All profiles, defaults first, each row with `settings` decoded and normalized.
     *
     * @return array<int, array>
     */
    public function all(): array
    {
        $rows = $this->db->select("SELECT * FROM %%BOT_PROFILES%% ORDER BY is_default DESC, id ASC;");
        foreach ($rows as &$row) {
            $row['settings'] = $this->decode((string) $row['settings'], (string) $row['profile_key']);
        }
        unset($row);

        return $rows;
    }

    /** One profile row with normalized `settings`, null when it does not exist. */
    public function get(int $id): ?array
    {
        $row = $this->db->selectSingle(
            "SELECT * FROM %%BOT_PROFILES%% WHERE id = :id;",
            [':id' => $id],
        );
        if (empty($row)) {
            return null;
        }
        $row['settings'] = $this->decode((string) $row['settings'], (string) $row['profile_key']);

        return $row;
    }

    /**
     * Profile as the engine uses it: the row (settings normalized) plus the settings on their own.
     *
     * @return array{row: array, settings: array}|null
     */
    public function load(int $id): ?array
    {
        $row = $this->get($id);

        return $row === null ? null : ['row' => $row, 'settings' => $row['settings']];
    }

    /**
     * Saves a profile. $data: id (0 = new), profile_key, name, description, settings (array).
     * Returns the profile id.
     */
    public function save(array $data): int
    {
        $db = $this->db;
        $id = (int) ($data['id'] ?? 0);
        $settings = json_encode($this->normalizeSettings((array) ($data['settings'] ?? [])));
        $name = mb_substr(trim((string) ($data['name'] ?? '')), 0, 64);
        $description = mb_substr(trim((string) ($data['description'] ?? '')), 0, 255);

        if ($id > 0) {
            $db->update(
                "UPDATE %%BOT_PROFILES%% SET name = :name, description = :description, settings = :settings WHERE id = :id;",
                [':name' => $name, ':description' => $description, ':settings' => $settings, ':id' => $id],
            );
            return $id;
        }

        $key = preg_replace('/[^a-z0-9_]/', '', strtolower((string) ($data['profile_key'] ?? '')));
        if ($key === '') {
            $key = 'custom_' . substr(bin2hex(random_bytes(4)), 0, 8);
        }
        $key = substr($key, 0, 32);
        $base = $key;
        $i = 2;
        while ($db->selectSingle("SELECT id FROM %%BOT_PROFILES%% WHERE profile_key = :k;", [':k' => $key], 'id')) {
            $suffix = '_' . $i++;
            $key = substr($base, 0, 32 - strlen($suffix)) . $suffix;
        }

        $db->insert(
            "INSERT INTO %%BOT_PROFILES%% SET profile_key = :k, name = :name, description = :description, is_default = 0, settings = :settings, created_at = :t;",
            [':k' => $key, ':name' => $name, ':description' => $description, ':settings' => $settings, ':t' => $this->state->runtime->timestamp],
        );

        return $db->lastInsertId();
    }

    /** Deletes a non-default profile that no bot uses. */
    public function delete(int $id): void
    {
        $this->db->delete(
            "DELETE FROM %%BOT_PROFILES%% WHERE id = :id AND is_default = 0
             AND NOT EXISTS (SELECT 1 FROM %%BOT_PLAYERS%% WHERE profile_id = :id2);",
            [':id' => $id, ':id2' => $id],
        );
    }

    /** Writes the five default profiles (by profile_key): existing ones are reset to the defaults and marked is_default, missing ones are inserted. Custom profiles stay. */
    public function restoreDefaults(): void
    {
        $db = $this->db;
        foreach ($this->defaults() as $key => $profile) {
            $json = json_encode($profile['settings']);
            $exists = $db->selectSingle("SELECT id FROM %%BOT_PROFILES%% WHERE profile_key = :k;", [':k' => $key], 'id');
            if ($exists) {
                $db->update(
                    "UPDATE %%BOT_PROFILES%% SET name = :n, description = :d, is_default = 1, settings = :s WHERE id = :id;",
                    [':n' => $profile['name'], ':d' => $profile['description'], ':s' => $json, ':id' => $exists],
                );
            } else {
                $db->insert(
                    "INSERT INTO %%BOT_PROFILES%% SET profile_key = :k, name = :n, description = :d, is_default = 1, settings = :s, created_at = :t;",
                    [':k' => $key, ':n' => $profile['name'], ':d' => $profile['description'], ':s' => $json, ':t' => $this->state->runtime->timestamp],
                );
            }
        }
    }

    /**
     * Fills missing keys with the engine defaults (the "office_worker" profile) and clamps values to valid ranges:
     * `activity` = 24 hourly play probabilities 0-1; `check_interval` (minutes between online checks),
     * `session_minutes`, `actions_per_session` = [min, max] pairs; `weights` = relative build priorities 0-1000 per
     * category; `*_chance` values in percent 0-100 (`alliance_join_chance`: roll per social turn to apply at a bot alliance; `fleetsave_chance`: chance to fleetsave when a session ends or a threat shows; `saving_discipline`: how strictly the bot saves for its goal instead of buying cheaper things); `expedition_slots` = fleet slots kept free for expeditions (0-5); skill values 0-1 (`skill_gain_per_day` is added per day of bot age);
     * `pattern_shift_hours` every `pattern_shift_days` moves the daily rhythm; `chat_style` is a plain-text hint for the LLM.
     * Raid settings (see RAID_KEYS): `raid_enabled`, `raid_chance` (percent of sessions that go raiding), `scout_range` (systems
     * around the own planets), `min_profit_ratio` (expected loot divided by fuel and losses), `relentless_minutes` = [min, max]
     * of the raid streak after a hit (0 = none), `recall_check_chance` (percent, pre-impact re-spy), `max_attacks_per_target_per_day`,
     * `attack_bots` / `attack_humans`, `prefer_inactive` (0-100 weight of inactive targets).
     *
     * @param array<string, mixed> $in raw settings (e.g. from the admin form or JSON)
     * @param array<string, mixed>|null $raidDefaults raid values for missing raid keys (default: office worker without raids' extras, see RAID_FALLBACK)
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $in, ?array $raidDefaults = null): array
    {
        $base = $this->baseSettings();
        $out = [];

        $activity = array_values((array) ($in['activity'] ?? $base['activity']));
        for ($i = 0; $i < 24; $i++) {
            $out['activity'][$i] = self::clampFloat($activity[$i] ?? 0, 0, 1);
        }
        $out['pattern_shift_hours'] = self::clampInt($in['pattern_shift_hours'] ?? 0, 0, 23);
        $out['pattern_shift_days'] = self::clampInt($in['pattern_shift_days'] ?? 0, 0, 365);

        foreach (['check_interval' => [1, 1440], 'session_minutes' => [1, 600], 'actions_per_session' => [1, 100]] as $k => [$lo, $hi]) {
            $pair = array_values((array) ($in[$k] ?? $base[$k]));
            $a = self::clampInt($pair[0] ?? $base[$k][0], $lo, $hi);
            $b = self::clampInt($pair[1] ?? $base[$k][1], $lo, $hi);
            $out[$k] = [min($a, $b), max($a, $b)];
        }

        $weights = (array) ($in['weights'] ?? []);
        foreach ($base['weights'] as $k => $default) {
            $out['weights'][$k] = self::clampInt($weights[$k] ?? $default, 0, 1000);
        }

        $out['expedition_chance'] = self::clampInt($in['expedition_chance'] ?? $base['expedition_chance'], 0, 100);
        $out['colonize_chance'] = self::clampInt($in['colonize_chance'] ?? $base['colonize_chance'], 0, 100);
        $out['skill_start'] = self::clampFloat($in['skill_start'] ?? $base['skill_start'], 0, 1);
        $out['skill_max'] = max($out['skill_start'], self::clampFloat($in['skill_max'] ?? $base['skill_max'], 0, 1));
        $out['skill_gain_per_day'] = self::clampFloat($in['skill_gain_per_day'] ?? 0, 0, 1);
        $out['mistake_chance'] = self::clampInt($in['mistake_chance'] ?? $base['mistake_chance'], 0, 100);
        $out['randomness'] = self::clampInt($in['randomness'] ?? $base['randomness'], 0, 100);
        $out['llm_advice'] = !empty($in['llm_advice']);
        $out['reply_chance'] = self::clampInt($in['reply_chance'] ?? 80, 0, 100);
        $out['message_chance'] = self::clampInt($in['message_chance'] ?? 5, 0, 100);
        $out['alliance_chance'] = self::clampInt($in['alliance_chance'] ?? 20, 0, 100);
        $out['alliance_join_chance'] = self::clampInt($in['alliance_join_chance'] ?? 5, 0, 100);
        $out['fleetsave_chance'] = self::clampInt($in['fleetsave_chance'] ?? $base['fleetsave_chance'], 0, 100);
        $out['saving_discipline'] = self::clampInt($in['saving_discipline'] ?? $base['saving_discipline'], 0, 100);
        $out['expedition_slots'] = self::clampInt($in['expedition_slots'] ?? $base['expedition_slots'], 0, 5);
        $out['buddy_accept_chance'] = self::clampInt($in['buddy_accept_chance'] ?? 60, 0, 100);
        $out['chat_style'] = mb_substr(trim(strip_tags((string) ($in['chat_style'] ?? 'short'))), 0, 120);

        return $out + $this->normalizeRaidSettings($in, $raidDefaults ?? self::RAID_FALLBACK);
    }

    /**
     * The raid part of the settings: missing keys come from `$defaults`, values are clamped (percent 0-100, scout range
     * 1-200 systems, profit ratio 0.5-100, streak 0-600 minutes as ordered pair, attacks per target and day 1-50).
     *
     * @param array<string, mixed> $in raw settings
     * @param array<string, mixed> $defaults raid values used for missing keys
     * @return array<string, mixed>
     */
    private function normalizeRaidSettings(array $in, array $defaults): array
    {
        $in += $defaults;
        $streak = array_values((array) $in['relentless_minutes']);
        $a = self::clampInt($streak[0] ?? 0, 0, 600);
        $b = self::clampInt($streak[1] ?? $a, 0, 600);

        return [
            'raid_enabled' => !empty($in['raid_enabled']),
            'raid_chance' => self::clampInt($in['raid_chance'], 0, 100),
            'scout_range' => self::clampInt($in['scout_range'], 1, 200),
            'min_profit_ratio' => self::clampFloat($in['min_profit_ratio'], 0.5, 100),
            'relentless_minutes' => [min($a, $b), max($a, $b)],
            'recall_check_chance' => self::clampInt($in['recall_check_chance'], 0, 100),
            'max_attacks_per_target_per_day' => self::clampInt($in['max_attacks_per_target_per_day'], 1, 50),
            'attack_bots' => !empty($in['attack_bots']),
            'attack_humans' => !empty($in['attack_humans']),
            'prefer_inactive' => self::clampInt($in['prefer_inactive'], 0, 100),
        ];
    }

    /** JSON settings string to normalized settings; invalid JSON gives the defaults. */
    private function decode(string $json, string $profileKey = ''): array
    {
        $data = json_decode($json, true);
        // profiles saved before the raid settings existed get the raid values of their default profile
        $raidDefaults = isset($this->defaults()[$profileKey]) ? $this->raidOf($this->defaults()[$profileKey]['settings']) : null;

        $data = is_array($data) ? $data : [];
        if (isset($this->defaults()[$profileKey])) {
            $data += array_intersect_key($this->defaults()[$profileKey]['settings'], array_flip(['alliance_join_chance', 'fleetsave_chance', 'saving_discipline', 'expedition_slots']));
        }

        return $this->normalizeSettings($data, $raidDefaults);
    }

    /**
     * The raid keys of a settings array.
     *
     * @param array<string, mixed> $settings
     * @return array<string, mixed>
     */
    private function raidOf(array $settings): array
    {
        return array_intersect_key($settings, array_flip(array_keys(self::RAID_FALLBACK)));
    }

    /** `$v` as integer within [min, max]. */
    private static function clampInt(mixed $v, int $min, int $max): int
    {
        return max($min, min($max, (int) $v));
    }

    /** `$v` as float within [min, max], rounded to 4 decimals. */
    private static function clampFloat(mixed $v, float $min, float $max): float
    {
        return round(max($min, min($max, (float) $v)), 4);
    }

    /** Fallback values for missing settings. */
    private function baseSettings(): array
    {
        return $this->defaults()['office_worker']['settings'];
    }

    /**
     * The five default profiles (same data as the SQL seed).
     *
     * @return array<string, array{name: string, description: string, settings: array}>
     */
    public function defaults(): array
    {
        return [
            'office_worker' => $this->officeWorker(),
            'jobless' => $this->jobless(),
            'shift_worker' => $this->shiftWorker(),
            'lunatic' => $this->lunatic(),
            'noob' => $this->noob(),
        ];
    }

    /** Raid settings of a profile that has none stored and is not one of the defaults: cautious, rarely raids. */
    private const RAID_FALLBACK = [
        'raid_enabled' => true,
        'raid_chance' => 8,
        'scout_range' => 15,
        'min_profit_ratio' => 4.0,
        'relentless_minutes' => [10, 25],
        'recall_check_chance' => 20,
        'max_attacks_per_target_per_day' => 2,
        'attack_bots' => true,
        'attack_humans' => true,
        'prefer_inactive' => 80,
    ];

    /** Settings every default profile shares (profiles override single keys). */
    private const COMMON_SETTINGS = [
        'pattern_shift_hours' => 0,
        'pattern_shift_days' => 0,
        'skill_gain_per_day' => 0.0,
        'llm_advice' => true,
    ];

    /**
     * Activity per hour of the day: 24 values, 0.0 where no range applies; later ranges overwrite earlier ones.
     *
     * @param list<array{0: int, 1: int, 2: float}> $ranges [from hour, to hour (both inclusive), value]
     * @return list<float>
     */
    private function hourly(array $ranges): array
    {
        $a = array_fill(0, 24, 0.0);
        foreach ($ranges as [$from, $to, $value]) {
            for ($h = $from; $h <= $to; $h++) {
                $a[$h] = $value;
            }
        }
        return $a;
    }

    /**
     * The category weights of the build priorities.
     *
     * @return array{mines: int, energy: int, storage: int, facilities: int, research: int, ships: int, defense: int}
     */
    private function weights(int $m, int $e, int $st, int $f, int $r, int $sh, int $d): array
    {
        return [
            'mines' => $m, 'energy' => $e, 'storage' => $st, 'facilities' => $f,
            'research' => $r, 'ships' => $sh, 'defense' => $d,
        ];
    }

    /**
     * Raid settings of a default profile (see normalizeSettings()).
     *
     * @param array{0: int, 1: int} $streak relentless_minutes
     * @return array<string, mixed>
     */
    private function raid(int $chance, int $range, float $ratio, array $streak, int $recall, int $maxAttacks, int $preferInactive, bool $enabled = true): array
    {
        return [
            'raid_enabled' => $enabled,
            'raid_chance' => $chance,
            'scout_range' => $range,
            'min_profit_ratio' => $ratio,
            'relentless_minutes' => $streak,
            'recall_check_chance' => $recall,
            'max_attacks_per_target_per_day' => $maxAttacks,
            'attack_bots' => true,
            'attack_humans' => true,
            'prefer_inactive' => $preferInactive,
        ];
    }

    /** Office worker: plays before work, at lunch and in the evening. */
    private function officeWorker(): array
    {
        return [
            'name' => 'Office worker',
            'description' => 'Plays before work, at lunch and in the evening.',
            'settings' => [
                'activity' => $this->hourly([[0, 5, 0.05], [6, 7, 0.6], [8, 11, 0.15], [12, 12, 0.6], [13, 16, 0.15], [17, 22, 0.7], [23, 23, 0.3]]),
                'check_interval' => [20, 60],
                'session_minutes' => [5, 15],
                'actions_per_session' => [2, 5],
                'weights' => $this->weights(42, 15, 6, 12, 17, 5, 3),
                'expedition_chance' => 6,
                'colonize_chance' => 30,
                'skill_start' => 0.75,
                'skill_max' => 0.9,
                'mistake_chance' => 8,
                'randomness' => 20,
                'reply_chance' => 50,
                'message_chance' => 3,
                'alliance_chance' => 10,
                'alliance_join_chance' => 3,
                'fleetsave_chance' => 60,
                'saving_discipline' => 70,
                'expedition_slots' => 0,
                'buddy_accept_chance' => 50,
                'chat_style' => 'short, polite, businesslike, few words',
            ] + $this->raid(8, 15, 4.0, [10, 25], 20, 2, 80) + self::COMMON_SETTINGS,
        ];
    }

    /** Jobless: lots of time, online most of the afternoon and night. */
    private function jobless(): array
    {
        return [
            'name' => 'Jobless',
            'description' => 'Lots of time, online most of the afternoon and night.',
            'settings' => [
                'activity' => $this->hourly([[0, 3, 0.6], [4, 9, 0.1], [10, 11, 0.5], [12, 23, 0.8]]),
                'check_interval' => [10, 30],
                'session_minutes' => [10, 40],
                'actions_per_session' => [3, 9],
                'weights' => $this->weights(38, 14, 6, 12, 16, 10, 4),
                'expedition_chance' => 15,
                'colonize_chance' => 40,
                'skill_start' => 0.8,
                'skill_max' => 0.92,
                'mistake_chance' => 6,
                'randomness' => 25,
                'reply_chance' => 95,
                'message_chance' => 10,
                'alliance_chance' => 25,
                'alliance_join_chance' => 6,
                'fleetsave_chance' => 85,
                'saving_discipline' => 55,
                'expedition_slots' => 1,
                'buddy_accept_chance' => 70,
                'chat_style' => 'chatty, casual, relaxed, uses slang',
            ] + $this->raid(45, 30, 2.5, [30, 90], 50, 3, 50) + self::COMMON_SETTINGS,
        ];
    }

    /** Shift worker: the play pattern moves by 8 hours every week. */
    private function shiftWorker(): array
    {
        // shift worker: 8h block at 0.7 (08-15), 4h at 0.4 (16-19), rest 0.05
        $shift = $this->hourly([[0, 23, 0.05], [8, 15, 0.7], [16, 19, 0.4]]);

        return [
            'name' => 'Shift worker',
            'description' => 'Rotating shifts: the play pattern moves by 8 hours every week.',
            'settings' => [
                'activity' => $shift,
                'check_interval' => [15, 45],
                'session_minutes' => [5, 25],
                'actions_per_session' => [2, 7],
                'weights' => $this->weights(40, 15, 7, 12, 16, 6, 4),
                'expedition_chance' => 8,
                'colonize_chance' => 30,
                'skill_start' => 0.75,
                'skill_max' => 0.9,
                'mistake_chance' => 9,
                'randomness' => 25,
                'reply_chance' => 75,
                'message_chance' => 5,
                'alliance_chance' => 20,
                'alliance_join_chance' => 5,
                'fleetsave_chance' => 70,
                'saving_discipline' => 65,
                'expedition_slots' => 1,
                'buddy_accept_chance' => 60,
                'chat_style' => 'medium length, friendly, a bit tired',
            ] + ['pattern_shift_hours' => 8, 'pattern_shift_days' => 7] + $this->raid(20, 20, 3.0, [15, 45], 35, 2, 60) + self::COMMON_SETTINGS,
        ];
    }

    /** Lunatic: always online, long sessions, almost perfect play. */
    private function lunatic(): array
    {
        return [
            'name' => 'Lunatic',
            'description' => 'Always online, long sessions, plays almost perfectly.',
            'settings' => [
                'activity' => $this->hourly([[0, 7, 0.7], [8, 23, 0.9]]),
                'check_interval' => [3, 10],
                'session_minutes' => [20, 60],
                'actions_per_session' => [5, 15],
                'weights' => $this->weights(36, 13, 5, 14, 20, 9, 3),
                'expedition_chance' => 25,
                'colonize_chance' => 60,
                'skill_start' => 0.92,
                'skill_max' => 0.98,
                'mistake_chance' => 2,
                'randomness' => 10,
                'reply_chance' => 95,
                'message_chance' => 15,
                'alliance_chance' => 30,
                'alliance_join_chance' => 8,
                'fleetsave_chance' => 98,
                'saving_discipline' => 90,
                'expedition_slots' => 2,
                'buddy_accept_chance' => 80,
                'chat_style' => 'very chatty, enthusiastic, long messages, emojis',
            ] + $this->raid(70, 50, 2.0, [60, 180], 80, 4, 30) + self::COMMON_SETTINGS,
        ];
    }

    /** Noob: many mistakes, improves slowly. */
    private function noob(): array
    {
        return [
            'name' => 'Noob',
            'description' => 'Still learning: makes many mistakes, improves slowly.',
            'settings' => [
                'activity' => $this->hourly([[0, 23, 0.3], [15, 21, 0.5]]),
                'check_interval' => [15, 90],
                'session_minutes' => [3, 15],
                'actions_per_session' => [1, 5],
                'weights' => $this->weights(25, 10, 10, 15, 10, 15, 15),
                'expedition_chance' => 5,
                'colonize_chance' => 15,
                'skill_start' => 0.2,
                'skill_max' => 0.85,
                'skill_gain_per_day' => 0.03,
                'mistake_chance' => 45,
                'randomness' => 40,
                'reply_chance' => 90,
                'message_chance' => 6,
                'alliance_chance' => 40,
                'alliance_join_chance' => 4,
                'fleetsave_chance' => 15,
                'saving_discipline' => 25,
                'expedition_slots' => 0,
                'buddy_accept_chance' => 85,
                'chat_style' => 'friendly, curious newbie, asks naive questions, typos',
            ] + $this->raid(0, 5, 8.0, [0, 0], 0, 1, 90, false) + self::COMMON_SETTINGS,
        ];
    }
}
