<?php

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * All in-game messages the alliance system sends: application and leave notices to the managers, circular messages
 * to members, and the per-language diplomacy messages to the two alliances involved. Every message is of type
 * MESSAGE_TYPE_ALLIANCE and written in the receiver's own language.
 */
class AllianceMessenger
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
    ) {}

    /** Tells the managers (leader and MANAGEAPPLY ranks) of the alliance that the acting user applied. */
    public function applicationReceived(int|string $allianceId): void
    {
        $USER = & $this->state->USER;
        foreach ($this->repository->messageReceivers($allianceId, $USER['ally_id'], false, true) as $receiver) {
            $lang = $this->playerUtil()->getLanguage($receiver['lang']);

            $applyMessage = sprintf(
                $lang['al_new_apply'],
                $USER['id'],
                $USER['username'],
                $USER['username'],
            );

            $this->playerUtil()->sendMessage(
                $receiver['id'],
                0,
                $lang['al_alliance'],
                MESSAGE_TYPE_ALLIANCE,
                $lang['al_request'],
                $applyMessage,
                $this->state->runtime->timestamp,
            );
        }
    }

    /** Tells the managers of the alliance (except the acting user) that the acting user left. */
    public function memberLeft(int|string $allianceId): void
    {
        $USER = & $this->state->USER;
        foreach ($this->repository->messageReceivers($allianceId, $USER['ally_id'], false, true) as $receiver) {
            if ($receiver['id'] == $USER['id']) {
                continue;
            }
            $lang = $this->playerUtil()->getLanguage($receiver['lang']);

            $applyMessage = sprintf(
                $lang['al_leaving_msg'],
                $USER['id'],
                $USER['username'],
                $USER['username'],
            );

            $this->playerUtil()->sendMessage(
                $receiver['id'],
                0,
                $lang['al_alliance'],
                MESSAGE_TYPE_ALLIANCE,
                $lang['al_leaving'],
                $applyMessage,
                $this->state->runtime->timestamp,
            );
        }
    }

    /**
     * Circular message of the acting user to all members of the alliance or to one rank.
     *
     * Side effect: one message (sender = acting user) to every receiver.
     *
     * @param array<string, mixed> $alliance
     * @return string the confirmation text listing the receivers' names (one per line)
     * @throws AllianceException json error when the text is empty
     */
    public function sendCircular(array $alliance, int|string $rankId, string $subject, string $text): string
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;

        if (empty($text)) {
            throw AllianceException::json(['message' => $LNG['mg_empty_text'], 'error' => true]);
        }

        $db = $this->db;
        if ($rankId == 0) {
            $sql = 'SELECT id, username FROM %%USERS%% WHERE ally_id = :AllianceID;';
            $sendUsersResult = $db->select($sql, [
                ':AllianceID' => $alliance['id'],
            ]);
        } else {
            $sql = 'SELECT id, username FROM %%USERS%% WHERE ally_id = :AllianceID AND ally_rank_id = :RankID;';
            $sendUsersResult = $db->select($sql, [
                ':AllianceID' => $alliance['id'],
                ':RankID' => $rankId,
            ]);
        }

        $sendList = $LNG['al_circular_sended'];
        $title = $LNG['al_circular_alliance'] . $alliance['ally_tag'];
        $text = sprintf($LNG['al_circular_front_text'], $USER['username']) . "\r\n" . $text;

        $receivers = [];
        foreach ($sendUsersResult as $sendUsersRow) {
            $receivers[] = $sendUsersRow['id'];
            $sendList .= "\n" . $sendUsersRow['username'];
        }
        $this->playerUtil()->sendMessage(
            $receivers,
            $USER['id'],
            $title,
            MESSAGE_TYPE_ALLIANCE,
            $subject,
            makebr($text),
            $this->state->runtime->timestamp,
        );

        return $sendList;
    }

    /**
     * Sends a diplomacy message to the receivers, grouped by language so that every group gets one message in its
     * own language (sender = acting user, sender name "<al_circular_alliance><own tag>").
     *
     * @param list<array{id: int|string, lang: string}> $receivers
     * @param string $ownTag tag of the acting alliance
     * @param callable(array<string, mixed>|\ArrayAccess): array{0: string, 1: string} $compose receives the language data and
     *        returns [subject, text]
     */
    public function sendDiplomatic(array $receivers, string $ownTag, callable $compose): void
    {
        $USER = & $this->state->USER;
        $langReceivers = [];
        foreach ($receivers as $receiver) {
            if (!isset($langReceivers[$receiver['lang']])) {
                $langReceivers[$receiver['lang']] = [
                    'receivers' => [],
                    'lang' => $this->playerUtil()->getLanguage($receiver['lang']),
                ];
            }
            $langReceivers[$receiver['lang']]['receivers'][] = $receiver['id'];
        }
        foreach ($langReceivers as $group) {
            [$subject, $text] = $compose($group['lang']);
            $this->playerUtil()->sendMessage(
                $group['receivers'],
                $USER['id'],
                $group['lang']['al_circular_alliance'] . $ownTag,
                MESSAGE_TYPE_ALLIANCE,
                $subject,
                $text,
                $this->state->runtime->timestamp,
            );
        }
    }
}
