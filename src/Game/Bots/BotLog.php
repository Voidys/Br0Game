<?php

namespace App\Game\Bots;

use App\Game\Core\Database;
use Throwable;

/**
 * Writes the bot action log (bot_log). Logging never breaks the caller.
 */
class BotLog
{
    public function __construct(private readonly Database $db) {}

    /** Inserts one log row for the bot (action cut to 32, detail to 255 characters); database errors are swallowed on purpose. */
    public function log(int $userId, int $universe, string $action, string $detail = ''): void
    {
        try {
            $this->db->insert(
                'INSERT INTO %%BOT_LOG%% SET user_id = :u, universe = :uni, `time` = :t, action = :a, detail = :d;',
                [
                    ':u' => $userId,
                    ':uni' => $universe,
                    ':t' => time(),
                    ':a' => mb_substr($action, 0, 32),
                    ':d' => mb_substr($detail, 0, 255),
                ],
            );
        } catch (Throwable $e) {
            // logging must never break anything
        }
    }
}
