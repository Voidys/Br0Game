<?php

declare(strict_types=1);

namespace App\Community;

use App\Game\Core\Database;

/**
 * Vote sites (table VOTE_SITES) and the vote log (VOTE_LOG): admin CRUD, the click-through of a player and the
 * postback of a vote site, with the rewards. A site rewards once per cooldown window and player: a site with
 * verification "none" pays on the click, a "postback" site pays when it calls back with the site secret and the one-time
 * token that was put into the vote URL. The rewards go to the main planet of the player.
 */
final class VoteSites
{
    public const VERIFY_NONE = 'none';
    public const VERIFY_POSTBACK = 'postback';

    /** reward resource id => planet column */
    public const RESOURCES = [901 => 'metal', 902 => 'crystal', 903 => 'deuterium'];

    public const MAX_REWARD = 1000000000;
    public const MAX_COOLDOWN_HOURS = 8760;

    /** vote log rows older than this many seconds are purged by the daily cronjob (90 days) */
    public const LOG_RETENTION = 7776000;

    public const CALLBACK_OK = 'ok';
    public const CALLBACK_FORBIDDEN = 'forbidden';
    public const CALLBACK_UNKNOWN_TOKEN = 'unknown_token';
    public const CALLBACK_USED = 'used';
    public const CALLBACK_COOLDOWN = 'cooldown';

    public function __construct(private readonly Database $db) {}

    /**
     * Sites of a universe in display order, each with the reward decoded to `rewards` (resource id => amount).
     *
     * @return list<array<string, mixed>>
     */
    public function sites(int $universe, bool $activeOnly = false): array
    {
        $rows = $this->db->select(
            'SELECT * FROM %%VOTE_SITES%% WHERE `universe` = :uni' . ($activeOnly ? ' AND `active` = 1' : '') . ' ORDER BY `sort` ASC, `id` ASC;',
            [':uni' => $universe],
        );

        return array_map($this->withRewards(...), $rows);
    }

    /** @return array<string, mixed>|null the site with decoded `rewards`, null when unknown */
    public function find(int $id): ?array
    {
        $row = $this->db->selectSingle('SELECT * FROM %%VOTE_SITES%% WHERE `id` = :id;', [':id' => $id]);

        return empty($row) ? null : $this->withRewards($row);
    }

    /** Is the voting module (MODULE_VOTING) switched on in the universe? Read straight from the table, so it works without the booted game. */
    public function moduleEnabled(int $universe): bool
    {
        return (int) $this->db->selectSingle('SELECT `state` FROM %%CONFIG_UNIVERSE_MODULES%% WHERE `uni` = :uni AND `module` = :module;', [':uni' => $universe, ':module' => MODULE_VOTING], 'state') === 1;
    }

    /** Is there an active site in the universe (the menu entry of the game)? */
    public function hasActive(int $universe): bool
    {
        return !empty($this->db->selectSingle('SELECT `id` FROM %%VOTE_SITES%% WHERE `universe` = :uni AND `active` = 1 LIMIT 1;', [':uni' => $universe]));
    }

    /**
     * Validates the admin form. Returns the cleaned values and the error keys (LNG keys of the admin domain); empty
     * errors mean the values may be stored.
     *
     * @param array<string, mixed> $post name, vote_url, image_url, cooldown_hours, verification, active, sort, reward[<resource id>]
     * @return array{0: array<string, mixed>, 1: list<string>}
     */
    public function validate(array $post): array
    {
        $errors = [];
        $name = mb_substr(trim(strip_tags((string) ($post['name'] ?? ''))), 0, 80);
        $voteUrl = trim((string) ($post['vote_url'] ?? ''));
        $imageUrl = trim((string) ($post['image_url'] ?? ''));
        if ($name === '') {
            $errors[] = 'vt_error_name';
        }
        if (!self::isHttpUrl($voteUrl)) {
            $errors[] = 'vt_error_vote_url';
        }
        if ($imageUrl !== '' && !self::isHttpUrl($imageUrl)) {
            $errors[] = 'vt_error_image_url';
        }

        $rewards = [];
        foreach (array_keys(self::RESOURCES) as $resource) {
            $amount = (int) (is_array($post['reward'] ?? null) ? ($post['reward'][$resource] ?? 0) : 0);
            if ($amount < 0 || $amount > self::MAX_REWARD) {
                $errors[] = 'vt_error_reward';
                $amount = 0;
            }
            $rewards[$resource] = $amount;
        }

        $verification = ($post['verification'] ?? '') === self::VERIFY_POSTBACK ? self::VERIFY_POSTBACK : self::VERIFY_NONE;

        return [[
            'name' => $name,
            'vote_url' => $voteUrl,
            'image_url' => $imageUrl,
            'cooldown_hours' => max(1, min(self::MAX_COOLDOWN_HOURS, (int) ($post['cooldown_hours'] ?? 24))),
            'verification' => $verification,
            'active' => empty($post['active']) ? 0 : 1,
            'sort' => max(-100000, min(100000, (int) ($post['sort'] ?? 0))),
            'reward' => json_encode($rewards, JSON_THROW_ON_ERROR),
        ], array_values(array_unique($errors))];
    }

    /**
     * Inserts (id 0) or updates a site from validated values; a new site gets a random postback secret, an update
     * renews it on request.
     *
     * @param array<string, mixed> $values result of validate()
     * @return int the id of the site
     */
    public function save(int $id, int $universe, array $values, int $now, bool $newSecret = false): int
    {
        $params = [
            ':name' => $values['name'], ':vote_url' => $values['vote_url'], ':image_url' => $values['image_url'],
            ':cooldown' => $values['cooldown_hours'], ':reward' => $values['reward'], ':verification' => $values['verification'],
            ':active' => $values['active'], ':sort' => $values['sort'],
        ];
        if ($id === 0) {
            $this->db->insert('INSERT INTO %%VOTE_SITES%% (`universe`, `name`, `vote_url`, `image_url`, `cooldown_hours`, `reward`, `verification`, `active`, `sort`, `secret`, `created`)
                VALUES (:uni, :name, :vote_url, :image_url, :cooldown, :reward, :verification, :active, :sort, :secret, :created);', $params + [
                ':uni' => $universe, ':secret' => self::randomToken(), ':created' => $now,
            ]);

            return $this->db->lastInsertId();
        }
        $this->db->update('UPDATE %%VOTE_SITES%% SET `name` = :name, `vote_url` = :vote_url, `image_url` = :image_url, `cooldown_hours` = :cooldown,
            `reward` = :reward, `verification` = :verification, `active` = :active, `sort` = :sort' . ($newSecret ? ', `secret` = :secret' : '')
            . ' WHERE `id` = :id AND `universe` = :uni;', $params + [':id' => $id, ':uni' => $universe] + ($newSecret ? [':secret' => self::randomToken()] : []));

        return $id;
    }

    /** Flips the active flag of a site of the universe. */
    public function setActive(int $id, int $universe, bool $active): void
    {
        $this->db->update('UPDATE %%VOTE_SITES%% SET `active` = :active WHERE `id` = :id AND `universe` = :uni;', [':active' => $active ? 1 : 0, ':id' => $id, ':uni' => $universe]);
    }

    /** Deletes a site of the universe with its log. */
    public function delete(int $id, int $universe): void
    {
        $this->db->delete('DELETE l FROM %%VOTE_LOG%% l INNER JOIN %%VOTE_SITES%% s ON s.`id` = l.`site_id` WHERE s.`id` = :id AND s.`universe` = :uni;', [':id' => $id, ':uni' => $universe]);
        $this->db->delete('DELETE FROM %%VOTE_SITES%% WHERE `id` = :id AND `universe` = :uni;', [':id' => $id, ':uni' => $universe]);
    }

    /**
     * Seconds until the player may vote on the site again (0 = now), counted from the last reward.
     *
     * @param array<string, mixed> $site
     */
    public function cooldownRemaining(array $site, int $userId, int $now): int
    {
        $last = $this->db->selectSingle(
            'SELECT MAX(`rewarded_at`) AS last FROM %%VOTE_LOG%% WHERE `site_id` = :site AND `user_id` = :user;',
            [':site' => $site['id'], ':user' => $userId],
            'last',
        );

        return $last === null ? 0 : max(0, (int) $last + (int) $site['cooldown_hours'] * 3600 - $now);
    }

    /**
     * The player clicks "vote". With the user row locked it checks the cooldown, logs the click with a fresh token and,
     * for verification "none", pays the reward in the same transaction (so a double click pays once). A site on
     * cooldown gets no new token.
     *
     * @param array<string, mixed> $site
     * @return array{status: string, url: string|null, remaining: int} status is "rewarded", "pending" (postback site: reward follows
     *         the callback), "cooldown" or "error" (unknown player)
     */
    public function click(array $site, int $userId, string $ip, int $now): array
    {
        return $this->db->transactional(function () use ($site, $userId, $ip, $now): array {
            $user = $this->lockUser($userId);
            if ($user === null) {
                return ['status' => 'error', 'url' => null, 'remaining' => 0];
            }
            $remaining = $this->cooldownRemaining($site, $userId, $now);
            if ($remaining > 0) {
                return ['status' => 'cooldown', 'url' => null, 'remaining' => $remaining];
            }

            $token = self::randomToken();
            $paysNow = $site['verification'] === self::VERIFY_NONE;
            $this->db->insert('INSERT INTO %%VOTE_LOG%% (`site_id`, `user_id`, `token`, `clicked_at`, `verified_at`, `rewarded_at`, `ip`)
                VALUES (:site, :user, :token, :now, :verified, :rewarded, :ip);', [
                ':site' => $site['id'], ':user' => $userId, ':token' => $token, ':now' => $now,
                ':verified' => $paysNow ? $now : null, ':rewarded' => $paysNow ? $now : null, ':ip' => mb_substr($ip, 0, 45),
            ]);
            if ($paysNow) {
                $this->credit($user, $site['rewards']);
            }

            return ['status' => $paysNow ? 'rewarded' : 'pending', 'url' => self::fillUrl((string) $site['vote_url'], $token, $userId), 'remaining' => 0];
        });
    }

    /**
     * The callback of a postback site: checks the secret in constant time, consumes the one-time token and pays the
     * reward (unless the cooldown of the player has not passed yet, then the token is used up without reward).
     *
     * @param array<string, mixed> $site
     * @return string one of the CALLBACK_* constants
     */
    public function callback(array $site, string $secret, string $token, int $now): string
    {
        if ($site['verification'] !== self::VERIFY_POSTBACK || $secret === '' || !hash_equals((string) $site['secret'], $secret)) {
            return self::CALLBACK_FORBIDDEN;
        }
        if (preg_match('/^[a-f0-9]{32}$/', $token) !== 1) {
            return self::CALLBACK_UNKNOWN_TOKEN;
        }

        return $this->db->transactional(function () use ($site, $token, $now): string {
            $log = $this->db->selectSingle('SELECT `id`, `user_id`, `verified_at` FROM %%VOTE_LOG%% WHERE `token` = :token AND `site_id` = :site FOR UPDATE;', [':token' => $token, ':site' => $site['id']]);
            if (empty($log)) {
                return self::CALLBACK_UNKNOWN_TOKEN;
            }
            if ($log['verified_at'] !== null) {
                return self::CALLBACK_USED;
            }
            $user = $this->lockUser((int) $log['user_id']);
            if ($user === null || (int) $user['universe'] !== (int) $site['universe']) {
                return self::CALLBACK_UNKNOWN_TOKEN;
            }
            if ($this->cooldownRemaining($site, (int) $log['user_id'], $now) > 0) {
                $this->db->update('UPDATE %%VOTE_LOG%% SET `verified_at` = :now WHERE `id` = :id;', [':now' => $now, ':id' => $log['id']]);

                return self::CALLBACK_COOLDOWN;
            }
            $this->db->update('UPDATE %%VOTE_LOG%% SET `verified_at` = :now, `rewarded_at` = :now WHERE `id` = :id;', [':now' => $now, ':id' => $log['id']]);
            $this->credit($user, $site['rewards']);

            return self::CALLBACK_OK;
        });
    }

    /**
     * The most recent log entries of a universe with site and player names.
     *
     * @return list<array<string, mixed>>
     */
    public function recent(int $universe, int $limit = 50): array
    {
        return $this->db->select('SELECT l.*, s.`name` AS site_name, u.`username` FROM %%VOTE_LOG%% l
            INNER JOIN %%VOTE_SITES%% s ON s.`id` = l.`site_id`
            LEFT JOIN %%USERS%% u ON u.`id` = l.`user_id`
            WHERE s.`universe` = :uni ORDER BY l.`id` DESC LIMIT :limit;', [':uni' => $universe, ':limit' => $limit]);
    }

    /**
     * Clicks, verified votes and rewards per site of a universe.
     *
     * @return array<int, array{clicks: int, verified: int, rewarded: int}> by site id
     */
    public function totals(int $universe): array
    {
        $totals = [];
        foreach ($this->db->select('SELECT l.`site_id`, COUNT(*) AS clicks, COUNT(l.`verified_at`) AS verified, COUNT(l.`rewarded_at`) AS rewarded
            FROM %%VOTE_LOG%% l INNER JOIN %%VOTE_SITES%% s ON s.`id` = l.`site_id` WHERE s.`universe` = :uni GROUP BY l.`site_id`;', [':uni' => $universe]) as $row) {
            $totals[(int) $row['site_id']] = ['clicks' => (int) $row['clicks'], 'verified' => (int) $row['verified'], 'rewarded' => (int) $row['rewarded']];
        }

        return $totals;
    }

    /** Deletes the vote log older than LOG_RETENTION (daily cronjob). */
    public function purge(int $now): void
    {
        $this->db->delete('DELETE FROM %%VOTE_LOG%% WHERE `clicked_at` < :limit;', [':limit' => $now - self::LOG_RETENTION]);
    }

    /** The vote URL with {token} and {userId} replaced (URL encoded). */
    public static function fillUrl(string $url, string $token, int $userId): string
    {
        return str_replace(['{token}', '{userId}'], [rawurlencode($token), (string) $userId], $url);
    }

    public static function isHttpUrl(string $url): bool
    {
        return preg_match('#^https?://[^\s<>"\']+$#i', $url) === 1 && mb_strlen($url) <= 500;
    }

    /** 32 hex characters from the system CSPRNG (tokens and secrets) */
    public static function randomToken(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Locks the player row for the rest of the transaction (serialises every reward of that player).
     *
     * @return array<string, mixed>|null id, id_planet, universe; null for an unknown player
     */
    private function lockUser(int $userId): ?array
    {
        $user = $this->db->selectSingle('SELECT `id`, `id_planet`, `universe` FROM %%USERS%% WHERE `id` = :id FOR UPDATE;', [':id' => $userId]);

        return empty($user) ? null : $user;
    }

    /**
     * Adds the reward to the main planet with a relative update (concurrent production or fleet updates keep their values).
     *
     * @param array<string, mixed> $user
     * @param array<int, int> $rewards resource id => amount
     */
    private function credit(array $user, array $rewards): void
    {
        $increments = [];
        foreach (self::RESOURCES as $resource => $column) {
            if (($rewards[$resource] ?? 0) > 0) {
                $increments[$column] = (int) $rewards[$resource];
            }
        }
        $this->db->updateRows('PLANETS', [], ['id' => (int) $user['id_planet']], [], $increments);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function withRewards(array $row): array
    {
        $decoded = json_decode((string) $row['reward'], true);
        $rewards = [];
        foreach (array_keys(self::RESOURCES) as $resource) {
            $rewards[$resource] = is_array($decoded) ? max(0, (int) ($decoded[$resource] ?? 0)) : 0;
        }
        $row['rewards'] = $rewards;

        return $row;
    }
}
