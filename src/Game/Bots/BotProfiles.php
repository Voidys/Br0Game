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
            $row['settings'] = $this->decode((string) $row['settings']);
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
        $row['settings'] = $this->decode((string) $row['settings']);

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
     * category; `*_chance` values in percent 0-100; skill values 0-1 (`skill_gain_per_day` is added per day of bot age);
     * `pattern_shift_hours` every `pattern_shift_days` moves the daily rhythm; `chat_style` is a plain-text hint for the LLM.
     *
     * @param array<string, mixed> $in raw settings (e.g. from the admin form or JSON)
     * @return array<string, mixed>
     */
    public function normalizeSettings(array $in): array
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
        $out['buddy_accept_chance'] = self::clampInt($in['buddy_accept_chance'] ?? 60, 0, 100);
        $out['chat_style'] = mb_substr(trim(strip_tags((string) ($in['chat_style'] ?? 'short'))), 0, 120);

        return $out;
    }

    /** JSON settings string to normalized settings; invalid JSON gives the defaults. */
    private function decode(string $json): array
    {
        $data = json_decode($json, true);

        return $this->normalizeSettings(is_array($data) ? $data : []);
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
        $fill = static function (array $ranges): array {
            $a = array_fill(0, 24, 0.0);
            foreach ($ranges as [$from, $to, $value]) {
                for ($h = $from; $h <= $to; $h++) {
                    $a[$h] = $value;
                }
            }
            return $a;
        };
        $common = [
            'pattern_shift_hours' => 0,
            'pattern_shift_days' => 0,
            'skill_gain_per_day' => 0.0,
            'llm_advice' => true,
        ];
        $w = static fn(int $m, int $e, int $st, int $f, int $r, int $sh, int $d): array => [
            'mines' => $m, 'energy' => $e, 'storage' => $st, 'facilities' => $f,
            'research' => $r, 'ships' => $sh, 'defense' => $d,
        ];

        // shift worker: 8h block at 0.7 (08-15), 4h at 0.4 (16-19), rest 0.05
        $shift = $fill([[0, 23, 0.05], [8, 15, 0.7], [16, 19, 0.4]]);

        return [
            'office_worker' => [
                'name' => 'Office worker',
                'description' => 'Plays before work, at lunch and in the evening.',
                'settings' => [
                    'activity' => $fill([[0, 5, 0.05], [6, 7, 0.6], [8, 11, 0.15], [12, 12, 0.6], [13, 16, 0.15], [17, 22, 0.7], [23, 23, 0.3]]),
                    'check_interval' => [20, 60],
                    'session_minutes' => [5, 15],
                    'actions_per_session' => [2, 5],
                    'weights' => $w(42, 15, 6, 12, 17, 5, 3),
                    'expedition_chance' => 6,
                    'colonize_chance' => 30,
                    'skill_start' => 0.75,
                    'skill_max' => 0.9,
                    'mistake_chance' => 8,
                    'randomness' => 20,
                    'reply_chance' => 50,
                    'message_chance' => 3,
                    'alliance_chance' => 10,
                    'buddy_accept_chance' => 50,
                    'chat_style' => 'short, polite, businesslike, few words',
                ] + $common,
            ],
            'jobless' => [
                'name' => 'Jobless',
                'description' => 'Lots of time, online most of the afternoon and night.',
                'settings' => [
                    'activity' => $fill([[0, 3, 0.6], [4, 9, 0.1], [10, 11, 0.5], [12, 23, 0.8]]),
                    'check_interval' => [10, 30],
                    'session_minutes' => [10, 40],
                    'actions_per_session' => [3, 9],
                    'weights' => $w(38, 14, 6, 12, 16, 10, 4),
                    'expedition_chance' => 15,
                    'colonize_chance' => 40,
                    'skill_start' => 0.8,
                    'skill_max' => 0.92,
                    'mistake_chance' => 6,
                    'randomness' => 25,
                    'reply_chance' => 95,
                    'message_chance' => 10,
                    'alliance_chance' => 25,
                    'buddy_accept_chance' => 70,
                    'chat_style' => 'chatty, casual, relaxed, uses slang',
                ] + $common,
            ],
            'shift_worker' => [
                'name' => 'Shift worker',
                'description' => 'Rotating shifts: the play pattern moves by 8 hours every week.',
                'settings' => [
                    'activity' => $shift,
                    'check_interval' => [15, 45],
                    'session_minutes' => [5, 25],
                    'actions_per_session' => [2, 7],
                    'weights' => $w(40, 15, 7, 12, 16, 6, 4),
                    'expedition_chance' => 8,
                    'colonize_chance' => 30,
                    'skill_start' => 0.75,
                    'skill_max' => 0.9,
                    'mistake_chance' => 9,
                    'randomness' => 25,
                    'reply_chance' => 75,
                    'message_chance' => 5,
                    'alliance_chance' => 20,
                    'buddy_accept_chance' => 60,
                    'chat_style' => 'medium length, friendly, a bit tired',
                ] + ['pattern_shift_hours' => 8, 'pattern_shift_days' => 7] + $common,
            ],
            'lunatic' => [
                'name' => 'Lunatic',
                'description' => 'Always online, long sessions, plays almost perfectly.',
                'settings' => [
                    'activity' => $fill([[0, 7, 0.7], [8, 23, 0.9]]),
                    'check_interval' => [3, 10],
                    'session_minutes' => [20, 60],
                    'actions_per_session' => [5, 15],
                    'weights' => $w(36, 13, 5, 14, 20, 9, 3),
                    'expedition_chance' => 25,
                    'colonize_chance' => 60,
                    'skill_start' => 0.92,
                    'skill_max' => 0.98,
                    'mistake_chance' => 2,
                    'randomness' => 10,
                    'reply_chance' => 95,
                    'message_chance' => 15,
                    'alliance_chance' => 30,
                    'buddy_accept_chance' => 80,
                    'chat_style' => 'very chatty, enthusiastic, long messages, emojis',
                ] + $common,
            ],
            'noob' => [
                'name' => 'Noob',
                'description' => 'Still learning: makes many mistakes, improves slowly.',
                'settings' => [
                    'activity' => $fill([[0, 23, 0.3], [15, 21, 0.5]]),
                    'check_interval' => [15, 90],
                    'session_minutes' => [3, 15],
                    'actions_per_session' => [1, 5],
                    'weights' => $w(25, 10, 10, 15, 10, 15, 15),
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
                    'buddy_accept_chance' => 85,
                    'chat_style' => 'friendly, curious newbie, asks naive questions, typos',
                ] + $common,
            ],
        ];
    }
}
