<?php

namespace App\Game\Bots;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;
use Throwable;

/**
 * Social behaviour of bots: answering private messages, buddy requests, alliances, greeting neighbours. Runs the steps of
 * one bot turn; the messaging and alliance steps live in BotSocialMessaging and BotSocialAlliance.
 * Everything goes through the game's own tables / $this->playerUtil()->sendMessage, each step rolled by a chance.
 */
class BotSocial
{
    use GameUtilities;

    public function __construct(
        private readonly Database $db,
        private readonly ConfigRegistry $configs,
        private readonly Singleton $state,
        private readonly BotPersona $persona,
        private readonly BotLog $botLog,
        private readonly BotSocialMessaging $messaging,
        private readonly BotSocialAlliance $alliance,
    ) {}

    /**
     * One social turn of a bot, after its gameplay actions: builds the context (own user row, decoded persona,
     * profile settings) and runs the steps reply, buddy, alliance, message in that order. A reply or message that was
     * sent blocks the later `message` step so a bot never sends two chat messages in one turn. Each step is isolated:
     * errors are written to the bot log ('social') and the next step still runs. Returns silently if the bot's user
     * row is gone.
     *
     * @param array<string, mixed> $botRow  row of {prefix}bot_players
     * @param array<string, mixed> $user    the bot's user row (fallback for ids)
     * @param array<string, mixed> $profile profile settings (chances in percent, see BotProfiles)
     */
    public function act(array $botRow, array $user, array $profile): void
    {
        $botId = (int) ($botRow['user_id'] ?? $user['id'] ?? 0);
        $universe = (int) ($botRow['universe'] ?? $user['universe'] ?? 1);
        if ($botId <= 0) {
            return;
        }

        try {
            $db = $this->db;
            $me = $db->selectSingle('SELECT id, username, ally_id, galaxy, `system`, planet, universe FROM %%USERS%% WHERE id = :id;', [':id' => $botId]);
            if (empty($me)) {
                return;
            }
            $ctx = [
                'bot' => $botId,
                'uni' => $universe,
                'me' => $me,
                'row' => $botRow,
                'persona' => BotPersona::decode($botRow['persona'] ?? null),
                'profile' => $profile,
            ];
        } catch (Throwable $e) {
            return;
        }

        $sent = false;
        $steps = [
            'reply' => fn() => $this->messaging->stepReply($ctx),
            'buddy' => fn() => $this->stepBuddy($ctx),
            'alliance' => fn() => $this->alliance->stepAlliance($ctx),
            'message' => function () use ($ctx, &$sent) {
                return $sent ? false : $this->messaging->stepMessage($ctx);
            },
        ];
        foreach ($steps as $name => $step) {
            try {
                $result = $step();
                if (($name === 'reply' || $name === 'message') && $result === true) {
                    $sent = true;
                }
            } catch (Throwable $e) {
                $this->botLog->log($botId, $universe, 'social', $name . ': ' . $e->getMessage());
            }
        }
    }

    /**
     * Answers the oldest pending buddy request addressed to the bot: accepts with the profile's
     * `buddy_accept_chance` (percent), otherwise declines (request removed, sender told) in 80 % of the cases and
     * ignores it in the rest. Writes buddy rows and a system message to the requester (in their language).
     * Always returns false (a buddy answer does not count as the turn's chat message).
     *
     * @param array<string, mixed> $ctx bot context built by act()
     */
    private function stepBuddy(array $ctx): bool
    {
        $db = $this->db;
        $bot = $ctx['bot'];
        $req = $db->selectSingle(
            'SELECT b.id, b.sender, u.username, us.lang FROM %%BUDDY%% b
             INNER JOIN %%BUDDY_REQUEST%% r ON r.id = b.id
             INNER JOIN %%USERS%% u ON u.id = b.sender
             LEFT JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
             WHERE b.owner = :bot AND b.universe = :uni ORDER BY b.id ASC LIMIT 1;',
            [':bot' => $bot, ':uni' => $ctx['uni']],
        );
        if (empty($req)) {
            return false;
        }

        $lang = $this->playerUtil()->getLanguage($req['lang'] ?: 'en');
        $accept = mt_rand(1, 100) <= (int) ($ctx['profile']['buddy_accept_chance'] ?? 60);
        if ($accept) {
            $db->delete('DELETE FROM %%BUDDY_REQUEST%% WHERE id = :id;', [':id' => $req['id']]);
            $this->playerUtil()->sendMessage(
                (int) $req['sender'],
                $bot,
                $ctx['me']['username'],
                MESSAGE_TYPE_SYSTEM,
                $lang['bu_accepted_request_title'],
                $lang->formatHtml('bu_accepted_request_body', $req['username'], $ctx['me']['username']),
                time(),
                1,
                $ctx['uni'],
            );
            $this->botLog->log($bot, $ctx['uni'], 'buddy', 'accepted #' . $req['sender']);
        } elseif (mt_rand(1, 100) <= 80) {
            $this->playerUtil()->sendMessage(
                (int) $req['sender'],
                $bot,
                $ctx['me']['username'],
                MESSAGE_TYPE_SYSTEM,
                $lang['bu_rejected_request_title'],
                $lang->formatHtml('bu_rejected_request_body', $req['username'], $ctx['me']['username']),
                time(),
                1,
                $ctx['uni'],
            );
            $db->delete('DELETE b.*, r.* FROM %%BUDDY%% b LEFT JOIN %%BUDDY_REQUEST%% r USING (id) WHERE b.id = :id;', [':id' => $req['id']]);
            $this->botLog->log($bot, $ctx['uni'], 'buddy', 'declined #' . $req['sender']);
        }

        return false;
    }
}
