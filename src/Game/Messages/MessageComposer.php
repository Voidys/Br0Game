<?php

namespace App\Game\Messages;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Sending and forwarding private messages: which recipients may receive (exist in the universe, accept private
 * messages, no direct-message block in either direction), the forbidden-content check with the automatic account ban,
 * and the actual delivery through PlayerUtil.
 */
class MessageComposer
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** The recipient exists in the current universe and has not disabled private messages. */
    private function canReceiveMessages(int $userId): bool
    {
        $sql = "SELECT COUNT(*) AS count FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id`
            WHERE u.`id` = :userId AND u.`universe` = :universe AND us.`settings_blockPM` = 0;";

        return (bool) $this->db->selectSingle($sql, [
            ':userId'   => $userId,
            ':universe' => $this->universe()->current(),
        ], 'count');
    }

    /**
     * Filters the requested recipients to those the current player may write to.
     *
     * @param mixed $receiver requested user ids (array or single id)
     * @return list<int|string> ids that exist, accept private messages and are not blocked in either direction
     */
    public function deliverableReceivers(mixed $receiver): array
    {
        $USER = & $this->state->USER;
        $receiverList = [];
        foreach ((array) $receiver as $receiverID) {
            if (!$this->canReceiveMessages((int) $receiverID)) {
                continue;
            }

            $blocked = $this->playerUtil()->get_block_status($USER['id'], $receiverID);
            $blockedInverted = $this->playerUtil()->get_block_status($receiverID, $USER['id']);

            if ($blocked['block_dm'] == 1 || $blockedInverted['block_dm'] == 1) {
                continue;
            }

            $receiverList[] = $receiverID;
        }

        return $receiverList;
    }

    /** Whether the message is in the current player's (not deleted) mailbox, i.e. may be forwarded by them. */
    public function ownsMessage(mixed $messageID): bool
    {
        $USER = & $this->state->USER;
        $sql = 'SELECT COUNT(*) as state FROM %%USERS_TO_MESSAGES%%
            WHERE `user_id` = :userId
              AND `message_id` = :messageId
              AND `deleted_at` IS NULL;';
        $ownsMessage = $this->db->selectSingle($sql, [
            ':userId'    => $USER['id'],
            ':messageId' => $messageID,
        ], 'state');

        return !empty($ownsMessage);
    }

    /** Forwards one of the player's messages to the receivers (see PlayerUtil::forwardMessage). */
    public function forward(array $receiverList, mixed $messageID): void
    {
        $USER = & $this->state->USER;
        $this->playerUtil()->forwardMessage($receiverList, $USER['id'], $messageID);
    }

    /**
     * Delivers a private message from the current player, unless its text or subject matches a forbidden pattern
     * (table messages_forbidden, applied to normal players only): then nothing is sent, the account is banned
     * ("Forbidden message content") and a Discord notice goes to the tickets hook.
     *
     * @param list<int|string> $receiverList deliverable recipient ids
     * @param mixed            $requested    the recipients as requested (only the last one is named in the Discord notice)
     * @return bool true when the message was blocked and the sender banned
     */
    public function send(array $receiverList, mixed $requested, string $subject, string $text, string $groupKey): bool
    {
        $USER = & $this->state->USER;
        $senderName	= $USER['username'] . ' [' . $USER['galaxy'] . ':' . $USER['system'] . ':' . $USER['planet'] . ']';
        $db = $this->db;
        $config = $this->configs->get();
        $sql = "SELECT `text` FROM %%MESSAGES_FORBIDDEN%%";
        $texts = $db->select($sql);
        $banned = false;
        if (!empty($texts) && $USER['authlevel'] == AUTH_USR) {
            // the last requested recipient, as the loop variable of the former inline code
            $requestedList = (array) $requested;
            $receiverID = end($requestedList);
            foreach ($texts as $textRow) {
                $regex = '/' . $textRow['text'] . '/i';
                if (preg_match($regex, $text) || preg_match($regex, $subject)) {
                    $banned = true;
                    $accountId = $this->playerUtil()->get_account_id($USER['id']);
                    $this->accountUtil()->banAccount($accountId, 'Forbidden message content');
                    $this->discord()->sendMessage($config->discord_tickets_hook, 'Automatic user message bann', [
                        'AccountID' => $accountId,
                        'User' => $USER['username'] . ' (' . $USER['id'] . ')',
                        'Forbidden Text' => $textRow['text'],
                        'Subject' => $subject,
                        'Message' => $text,
                        'ReceiverID' => $receiverID,
                    ]);
                    break;
                }
            }
        }

        if (!$banned) {
            $this->playerUtil()->sendMessage($receiverList, $USER['id'], $senderName, MESSAGE_TYPE_USER, $subject, $text, $this->state->runtime->timestamp, 1, null, $groupKey);
        }

        return $banned;
    }
}
