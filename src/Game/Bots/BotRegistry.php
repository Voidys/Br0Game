<?php

namespace App\Game\Bots;

use App\Game\Core\Database;
use App\Game\Core\Singleton;

/**
 * Read model for the admin page: the bots of a universe and the recent action log.
 */
class BotRegistry
{
    public function __construct(
        private readonly Database $db,
        private readonly Singleton $state,
    ) {}

    /**
     * The bots of a universe for the admin overview: profile name, skill in percent, total points (formatted, '-' when
     * not ranked yet), last action and next tick (d.m. H:i), whether a session is running now, action count and the
     * first 160 characters of the persona bio.
     *
     * @return list<array{user_id: int, username: string, profile_id: int, profile_name: string, skill: int|float,
     *     points: string, last_action: string, next_tick: string, online: bool, actions_total: int, bio: string}>
     */
    public function listBots(int $universe): array
    {
        $now = $this->state->runtime->timestamp;
        $bots = [];
        $rows = $this->db->select(
            'SELECT b.*, u.username, p.name AS profile_name,
                (SELECT s.total_points FROM %%STATPOINTS%% s WHERE s.id_owner = b.user_id AND s.stat_type = 1 LIMIT 1) AS points
            FROM %%BOT_PLAYERS%% b
            LEFT JOIN %%USERS%% u ON u.id = b.user_id
            LEFT JOIN %%BOT_PROFILES%% p ON p.id = b.profile_id
            WHERE b.universe = :uni ORDER BY b.user_id ASC;',
            [':uni' => $universe],
        );
        foreach ($rows as $b) {
            $persona = json_decode((string) $b['persona'], true);
            $bots[] = [
                'user_id' => (int) $b['user_id'],
                'username' => (string) $b['username'],
                'profile_id' => (int) $b['profile_id'],
                'profile_name' => (string) $b['profile_name'],
                'skill' => round($b['skill'] * 100),
                'points' => $b['points'] === null ? '-' : number_format((float) $b['points'], 0, ',', '.'),
                'last_action' => $b['last_action_at'] ? date('d.m. H:i', (int) $b['last_action_at']) : '-',
                'next_tick' => $b['next_tick'] ? date('d.m. H:i', (int) $b['next_tick']) : '-',
                'online' => (int) $b['session_until'] > $now,
                'actions_total' => (int) $b['actions_total'],
                'bio' => is_array($persona) && isset($persona['bio']) && is_string($persona['bio'])
                    ? mb_substr($persona['bio'], 0, 160) : '',
            ];
        }

        return $bots;
    }

    /**
     * Newest bot log entries of the universe, newest first; `$limit` is clamped to 1-500.
     *
     * @return list<array{time: string, username: string, action: string, detail: string}>
     */
    public function recentLog(int $universe, int $limit = 50): array
    {
        $limit = max(1, min(500, $limit));
        $log = [];
        $rows = $this->db->select(
            'SELECT l.time, l.action, l.detail, u.username FROM %%BOT_LOG%% l
            LEFT JOIN %%USERS%% u ON u.id = l.user_id
            WHERE l.universe = :uni ORDER BY l.id DESC LIMIT :limit;',
            [':uni' => $universe, ':limit' => $limit],
        );
        foreach ($rows as $l) {
            $log[] = [
                'time' => date('d.m. H:i:s', (int) $l['time']),
                'username' => (string) ($l['username'] ?? ''),
                'action' => (string) $l['action'],
                'detail' => (string) $l['detail'],
            ];
        }

        return $log;
    }
}
