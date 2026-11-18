<?php

namespace App\Game\Messages;

use App\Game\Enum\MessageType;
use Doctrine\DBAL\ArrayParameterType;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Mailbox actions of the current player on their messages (table users_to_messages): favourite toggle, soft delete,
 * mark read / delete in bulk by category or selection, and the recipient search of the write popup.
 *
 * Every write is scoped to the current player's own rows (`user_id`), deleted messages keep their row with `deleted_at`
 * set, favourites are never deleted in bulk. Used by MessagesController; it only performs the database work, the
 * controller answers (JSON / redirect).
 */
class MessageMailbox
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Flips the favourite flag of one of the player's messages.
     *
     * @return int the new state: 1 = now a favourite, 0 = no longer
     */
    public function toggleFavorite(mixed $favMessID): int
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "SELECT `message_favorite` FROM %%USERS_TO_MESSAGES%%
            WHERE `user_id` = :userID AND `message_id` = :messID;";
        $fav = $db->selectSingle($sql, [
            ':userID'   => $USER['id'],
            ':messID'   => $favMessID,
        ], 'message_favorite');

        if ($fav == 1) {
            $sql = "UPDATE %%USERS_TO_MESSAGES%% SET `message_favorite` = 0 
                WHERE `message_id` = :messID AND `user_id` = :userID;";
            $db->update($sql, [
                ':messID'   => $favMessID,
                ':userID'   => $USER['id'],
            ]);
            return 0;
        } else {
            $sql = "UPDATE %%USERS_TO_MESSAGES%% SET `message_favorite` = 1 
                WHERE `message_id` = :messID AND `user_id` = :userID;";
            $db->update($sql, [
                ':messID'   => $favMessID,
                ':userID'   => $USER['id'],
            ]);
            return 1;
        }
    }

    /** Soft-deletes one of the player's messages (sets `deleted_at` to now). */
    public function delete(mixed $delMessID): void
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "UPDATE %%USERS_TO_MESSAGES%% SET `deleted_at` = :timestamp 
            WHERE `message_id` = :messID AND `user_id` = :userID;";
        $db->update($sql, [
            ':timestamp'    => $this->state->runtime->timestamp,
            ':messID'       => $delMessID,
            ':userID'       => $USER['id'],
        ]);
    }

    /**
     * Runs a bulk action of the message list form. Unknown actions do nothing; bulk actions on a selection without a
     * valid message id do nothing either (the controller redirects back in every case).
     *
     * Actions: readall, readtypeall, readmarked, deleteall, deletetypeall, deletemarked, deleteunmarked, deletereadtype (read
     * messages of the category), deleteolder7 / deleteolder30 (messages of the category older than that many days). "deleteunmarked"
     * without a selection means "delete the whole category", and "...typeall" of the category ALL means "...all".
     *
     * @param string               $action     requested action
     * @param mixed                $MessCategory message type (MessageType value) the list showed
     * @param array<mixed, mixed>  $messageIDs  checkbox selection: message id => value
     */
    public function applyAction(string $action, mixed $MessCategory, mixed $messageIDs): void
    {
        if ($action == 'deleteunmarked' && empty($messageIDs)) {
            $action	= 'deletetypeall';
        }

        if ($action == 'deletetypeall' && $MessCategory == MessageType::All->value) {
            $action	= 'deleteall';
        }

        if ($action == 'readtypeall' && $MessCategory == MessageType::All->value) {
            $action	= 'readall';
        }

        switch ($action) {
            case 'readall':
                $this->markAllRead();
                break;
            case 'readtypeall':
                $this->markCategoryRead($MessCategory);
                break;
            case 'readmarked':
                $this->markSelectionRead($this->validIds($messageIDs));
                break;
            case 'deleteall':
                $this->deleteAll();
                break;
            case 'deletetypeall':
                $this->deleteCategory($MessCategory);
                break;
            case 'deletemarked':
                $this->deleteSelection($this->validIds($messageIDs));
                break;
            case 'deletereadtype':
                $this->deleteFiltered($MessCategory, true, 0);
                break;
            case 'deleteolder7':
            case 'deleteolder30':
                $this->deleteFiltered($MessCategory, false, (int) substr($action, 11));
                break;
            case 'deleteunmarked':
                $this->deleteUnselected($MessCategory, is_array($messageIDs) ? $this->validIds($messageIDs) : []);
                break;
        }
    }

    /**
     * Message ids of a checkbox selection (the keys); anything but a positive integer is dropped, so the ids are safe to
     * inline into SQL.
     *
     * @return list<int|string> ids, empty when nothing valid is selected
     */
    private function validIds(mixed $messageIDs): array
    {
        if (empty($messageIDs)) {
            return [];
        }
        $messageIDs = array_filter(
            $messageIDs,
            static fn($key) => ctype_digit((string) $key) && (int) $key > 0,
            ARRAY_FILTER_USE_KEY,
        );

        return array_keys($messageIDs);
    }

    private function markAllRead(): void
    {
        $USER = $this->state->USER;
        $sql = "UPDATE %%USERS_TO_MESSAGES%% 
                    SET `message_unread` = 0 WHERE `user_id` = :userID;";
        $this->db->update($sql, [
            ':userID'   => $USER['id'],
        ]);
    }

    private function markCategoryRead(mixed $MessCategory): void
    {
        $USER = $this->state->USER;
        $sql = "UPDATE %%USERS_TO_MESSAGES%% um
                    INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                    SET um.`message_unread` = 0
                    WHERE um.`user_id` = :userID
                      AND m.`message_type` = :messCategory;";
        $this->db->update($sql, [
            ':userID'       => $USER['id'],
            ':messCategory' => $MessCategory,
        ]);
    }

    /** @param list<int|string> $ids validated message ids */
    private function markSelectionRead(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $USER = $this->state->USER;
        $this->db->execute(
            $this->db->qb()
                ->update($this->db->table('USERS_TO_MESSAGES'))
                ->set('`message_unread`', '0')
                ->where('`user_id` = :userID')
                ->andWhere('`message_id` IN (:ids)')
                ->setParameter('userID', $USER['id'])
                ->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER),
        );
    }

    /** Deletes all messages of the player except favourites. */
    private function deleteAll(): void
    {
        $USER = $this->state->USER;
        $sql = "UPDATE %%USERS_TO_MESSAGES%%
                    SET `deleted_at` = :timestamp 
                    WHERE `user_id` = :userID
                      AND `message_favorite` = 0;";
        $this->db->update($sql, [
            ':timestamp'    => $this->state->runtime->timestamp,
            ':userID'       => $USER['id'],
        ]);
    }

    /** Deletes the messages of one category except favourites. */
    private function deleteCategory(mixed $MessCategory): void
    {
        $USER = $this->state->USER;
        $sql = "UPDATE %%USERS_TO_MESSAGES%% um
                    INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                    SET um.`deleted_at` = :timestamp
                    WHERE um.`user_id` = :userID
                      AND m.`message_type` = :messCategory
                      AND um.`message_favorite` = 0;";
        $this->db->update($sql, [
            ':timestamp' => $this->state->runtime->timestamp,
            ':userID' => $USER['id'],
            ':messCategory' => $MessCategory,
        ]);
    }

    /**
     * Deletes the non-favourite messages of a category (all categories for ALL), optionally only the read ones and/or only
     * those older than a number of days.
     *
     * @param bool $readOnly  only messages that were already read
     * @param int  $olderDays only messages older than this many days (0 = no age filter)
     */
    private function deleteFiltered(mixed $MessCategory, bool $readOnly, int $olderDays): void
    {
        $USER = $this->state->USER;
        $sql = "UPDATE %%USERS_TO_MESSAGES%% um
                    INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                    SET um.`deleted_at` = :timestamp
                    WHERE um.`user_id` = :userID
                      AND um.`message_favorite` = 0
                      AND um.`deleted_at` IS NULL";
        $params = [
            ':timestamp' => $this->state->runtime->timestamp,
            ':userID' => $USER['id'],
        ];
        if ($MessCategory != MessageType::All->value) {
            $sql .= ' AND m.`message_type` = :messCategory';
            $params[':messCategory'] = $MessCategory;
        }
        if ($readOnly) {
            $sql .= ' AND um.`message_unread` = 0';
        }
        if ($olderDays > 0) {
            $sql .= ' AND m.`message_time` < :olderThan';
            $params[':olderThan'] = $this->state->runtime->timestamp - $olderDays * 86400;
        }
        $this->db->update($sql . ';', $params);
    }

    /** @param list<int|string> $ids validated message ids */
    private function deleteSelection(array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $USER = $this->state->USER;
        $this->db->execute(
            $this->db->qb()
                ->update($this->db->table('USERS_TO_MESSAGES'))
                ->set('`deleted_at`', ':timestamp')
                ->where('`user_id` = :userId')
                ->andWhere('`message_favorite` = 0')
                ->andWhere('`message_id` IN (:ids)')
                ->setParameter('timestamp', $this->state->runtime->timestamp)
                ->setParameter('userId', $USER['id'])
                ->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER),
        );
    }

    /**
     * Deletes every non-favourite message (of the category unless it is ALL) that is not in the selection.
     *
     * @param list<int|string> $ids validated message ids to keep
     */
    private function deleteUnselected(mixed $MessCategory, array $ids): void
    {
        if (empty($ids)) {
            return;
        }
        $USER = $this->state->USER;
        $db = $this->db;
        // the former UPDATE ... INNER JOIN messages: a QueryBuilder update has no join, so the message must exist (and have
        // the category, unless ALL) as a subselect
        $messages = $db->table('MESSAGES');
        $qb = $db->qb()
            ->update($db->table('USERS_TO_MESSAGES'))
            ->set('`deleted_at`', ':timestamp')
            ->where('`user_id` = :userId')
            ->andWhere('`message_favorite` = 0')
            ->andWhere('`message_id` NOT IN (:ids)')
            ->setParameter('timestamp', $this->state->runtime->timestamp)
            ->setParameter('userId', $USER['id'])
            ->setParameter('ids', array_map('intval', $ids), ArrayParameterType::INTEGER);
        if ($MessCategory != MessageType::All->value) {
            $qb->andWhere('`message_id` IN (SELECT m.`message_id` FROM ' . $messages . ' m WHERE m.`message_type` = :messCategory)')
                ->setParameter('messCategory', $MessCategory);
        } else {
            $qb->andWhere('`message_id` IN (SELECT m.`message_id` FROM ' . $messages . ' m)');
        }
        $db->execute($qb);
    }

    /**
     * Recipient search of the write popup: players of the current universe whose name contains the text and who accept
     * private messages (at least 3 characters, otherwise nothing).
     *
     * @return list<array{id: int|string, username: string}>
     */
    public function searchRecipients(string $searchtext): array
    {
        if (empty($searchtext) || strlen($searchtext) < 3) {
            return [];
        }

        $sql = "SELECT u.`id`, u.`username` FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id`
            WHERE u.`username` LIKE :searchtext 
              AND u.`universe` = :universe 
              AND us.`settings_blockPM` = 0
            LIMIT 20;";

        return $this->db->select($sql, [
            ':searchtext'   => '%' . addcslashes($searchtext, '%_\\') . '%',
            ':universe'     => $this->universe()->current(),
        ]);
    }
}
