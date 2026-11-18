<?php

namespace App\Game\Core;

/**
 * Device links between accounts: the browser carries a random device id in the cookie `v0_device`; per player only
 * sha256(device id + secret) is stored (USER_DEVICES), never the id itself. Two accounts that share a hash count as
 * linked (see AccountLinks). Rows expire after RETENTION seconds without a visit (daily cronjob).
 *
 * Collaborators: Database (USER_DEVICES).
 */
class DeviceLinks
{
    public const COOKIE = 'v0_device';
    public const COOKIE_LIFETIME = 63072000; // 2 years
    public const RECORD_INTERVAL = 3600;
    public const RETENTION = 7776000; // 90 days

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected string $secret,
    ) {}

    /** A new random device id (64 hex characters). */
    public function newDeviceId(): string
    {
        return bin2hex(random_bytes(32));
    }

    /** Whether the cookie value looks like a device id we issued. */
    public function isValidDeviceId(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    public function hash(string $deviceId): string
    {
        return hash('sha256', $deviceId . $this->secret);
    }

    /** Stores or refreshes the (player, device) pair. */
    public function record(int $userId, string $deviceId, int $now): void
    {
        $this->db->insert(
            'INSERT INTO %%USER_DEVICES%% SET `user_id` = :userId, `device_hash` = :hash, `first_seen` = :now, `last_seen` = :now2
            ON DUPLICATE KEY UPDATE `last_seen` = :now3;',
            [':userId' => $userId, ':hash' => $this->hash($deviceId), ':now' => $now, ':now2' => $now, ':now3' => $now],
        );
    }

    /** Privacy: deletes the pairs not seen for RETENTION seconds. */
    public function purge(int $now): void
    {
        $this->db->delete('DELETE FROM %%USER_DEVICES%% WHERE `last_seen` < :limit;', [':limit' => $now - self::RETENTION]);
    }
}
