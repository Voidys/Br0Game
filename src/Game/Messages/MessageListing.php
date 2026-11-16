<?php

namespace App\Game\Messages;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Builds the message list page of the current player: category overview with unread/total counts, the filtered
 * (category, favourites, search) and paged list with grouping of conversations, and the per-row data the template needs.
 *
 * Side effect: the messages shown on the page are marked as read (listPage()).
 */
class MessageListing
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Category overview: for every message type (and the pseudo categories ALL, favourites, search) its colour, unread
     * count and total count of the player's non-deleted messages.
     *
     * @return array<int, array{color: string, unread: int|string, total: int|string}>
     */
    public function categoryList(): array
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $CategoryList   = [];
        $TitleColor = [
            MESSAGE_TYPE_SPY_REPORT         => '#FFFF00',
            MESSAGE_TYPE_SPY_DEFENCE        => '#FBE870',
            MESSAGE_TYPE_USER               => '#FF6699',
            MESSAGE_TYPE_ALLIANCE           => '#FF3300',
            MESSAGE_TYPE_BATTLE_REPORT      => '#FF9900',
            MESSAGE_TYPE_SYSTEM             => '#773399',
            MESSAGE_TYPE_TRANSPORT          => '#009933',
            MESSAGE_TYPE_FOREIGN_TRANSPORT  => '#005e1f',
            MESSAGE_TYPE_EXPEDITION         => '#6495ed',
            MESSAGE_TYPE_GAME               => '#666600',
            MESSAGE_TYPE_BUILD_LIST         => '#007070',
            MESSAGE_TYPE_ALL                => '#ABABAB',
            MESSAGE_TYPE_FAVORITES          => '#CCCCCC',
            MESSAGE_TYPE_SEARCH             => '#CCCCCC',
        ];
        $Total = $UnRead = array_fill_keys(array_keys($TitleColor), 0);

        $sql = "SELECT COUNT(*) as state FROM %%USERS_TO_MESSAGES%% 
            WHERE `user_id` = :userID AND `message_favorite` = 1 AND `deleted_at` IS NULL;";
        $MessFav = $db->selectSingle($sql, [
            ':userID'   => $USER['id'],
        ], 'state');

        $sql = "SELECT m.`message_type`, SUM(um.`message_unread`) as message_unread, COUNT(um.`id`) as count
            FROM %%USERS_TO_MESSAGES%% AS um
            INNER JOIN %%MESSAGES%% as m ON m.`message_id` = um.`message_id`
            WHERE um.`user_id` = :userID AND um.`deleted_at` IS NULL
            GROUP BY m.`message_type`;";
        $CategoryResult = $db->select($sql, [
            ':userID'   => $USER['id'],
        ]);

        foreach ($CategoryResult as $CategoryRow) {
            $UnRead[$CategoryRow['message_type']]   = $CategoryRow['message_unread'];
            $Total[$CategoryRow['message_type']]    = $CategoryRow['count'];
        }

        $UnRead[MESSAGE_TYPE_ALL]       = array_sum($UnRead);
        $Total[MESSAGE_TYPE_ALL]        = array_sum($Total);
        $Total[MESSAGE_TYPE_FAVORITES]  = $MessFav;

        foreach ($TitleColor as $CategoryID => $CategoryColor) {
            $CategoryList[$CategoryID]	= [
                'color'		=> $CategoryColor,
                'unread'	=> $UnRead[$CategoryID],
                'total'		=> $Total[$CategoryID],
            ];
        }

        return $CategoryList;
    }

    /**
     * SQL condition and parameters of the list for the category, the favourites switch and the search text (always
     * restricted to the player's non-deleted messages).
     *
     * @return array{0: string, 1: array<string, mixed>} condition on aliases um/m, named parameters
     */
    private function filter(mixed $MessCategory, mixed $fav, string $searchtext): array
    {
        $USER = $this->state->USER;
        $conditions = "um.`user_id` = :userId AND um.`deleted_at` IS NULL";
        $params = [':userId' => $USER['id']];

        if ($fav == 1 || $MessCategory == MESSAGE_TYPE_FAVORITES) {
            $conditions .= " AND um.`message_favorite` = 1";
        }

        if (!empty($searchtext)) {
            $conditions .= " AND (m.`message_subject` LIKE :searchtext OR m.`message_text` LIKE :searchtext)";
            $params[':searchtext'] = "%" . $searchtext . "%";
        }

        if ($MessCategory != MESSAGE_TYPE_ALL && $MessCategory != MESSAGE_TYPE_FAVORITES && $MessCategory != MESSAGE_TYPE_SEARCH) {
            $conditions .= " AND m.`message_type` = :messCategory";
            $params[':messCategory'] = $MessCategory;
        }

        return [$conditions, $params];
    }

    /**
     * Message types that are shown grouped by conversation: with the grouping cookie off only private messages,
     * otherwise also reports, system and transport messages.
     *
     * @return list<int>
     */
    private function groupedTypes(int $grp): array
    {
        $groupedArray = [
            MESSAGE_TYPE_SPY_REPORT,
            MESSAGE_TYPE_SPY_DEFENCE,
            MESSAGE_TYPE_USER,
            MESSAGE_TYPE_BATTLE_REPORT,
            MESSAGE_TYPE_SYSTEM,
            MESSAGE_TYPE_TRANSPORT,
            MESSAGE_TYPE_FOREIGN_TRANSPORT,
        ];
        if ($grp === 0) {
            $groupedArray = [
                MESSAGE_TYPE_USER,
            ];
        }

        return $groupedArray;
    }

    /**
     * Loads one page of the list (in groups of 4 messages per conversation block, newest first) and the page window.
     *
     * @param list<int> $groupedArray types shown grouped
     * @return array{rows: list<array<string, mixed>>, count: int|string, page: int, maxPage: int|float}
     */
    private function fetchPage(string $conditions, array $params, array $groupedArray, mixed $page, mixed $MessCategory, string $searchtext): array
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $grouped = '(' . implode(', ', $groupedArray) . ')';

        $sql = "SELECT COUNT(*) as `MessageCount` FROM %%USERS_TO_MESSAGES%% um
            INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
            WHERE $conditions;";
        $MessageCount = $db->selectSingle($sql, $params, 'MessageCount');
        $maxPage = max(1, ceil($MessageCount / $USER["messages_per_page"]));
        $page = max(1, min($page, $maxPage));

        $params[':offset'] = (($page - 1) * $USER["messages_per_page"]);
        $params[':limit'] = $USER["messages_per_page"];

        $sql = "SELECT DISTINCT m.`message_id`, CONCAT(m.`message_group`, '_', part.`group_partition`) AS grp_id, m.`message_group`, m.`message_time`, m.`message_from`, m.`message_subject`, um.`sender_id` as message_sender,
                    m.`message_type`, um.`message_unread`, um.`message_favorite`, um.`message_forwarded`, m.`message_text`, maxed.`LatestTimestamp`
            FROM %%USERS_TO_MESSAGES%% um
            JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
            JOIN (
                SELECT m2.`message_id`, m2.`message_group`, 
                    CEIL(ROW_NUMBER() OVER (PARTITION BY m2.`message_group` ORDER BY m2.`message_time` DESC) / 4) AS group_partition
                FROM %%MESSAGES%% m2
                JOIN %%USERS_TO_MESSAGES%% um2 ON um2.`message_id` = m2.`message_id`
                WHERE um2.`user_id` = :userId
                    AND um2.`deleted_at` IS NULL
                    AND m2.`message_type` IN $grouped
                    AND m2.`message_group` IS NOT NULL
                    GROUP BY m2.`message_id`, m2.`message_group`
            ) AS part ON part.`message_id` = m.`message_id`
            JOIN (
                SELECT grppart.`message_group`, grppart.`group_partition`, max(grppart.`LatestTimestamp`) AS LatestTimestamp
                FROM (
                    SELECT m3.`message_group`, MAX(m3.`message_time`) AS LatestTimestamp, 
                        CEIL(ROW_NUMBER() OVER (PARTITION BY m3.`message_group` ORDER BY m3.`message_time` DESC) / 4) AS group_partition
                    FROM %%MESSAGES%% m3
                    JOIN %%USERS_TO_MESSAGES%% um3 ON um3.`message_id` = m3.`message_id`
                    WHERE um3.`user_id` = :userId
                        AND um3.`deleted_at` IS NULL
                        AND m3.`message_type` IN $grouped
                        AND m3.`message_group` IS NOT NULL
                        GROUP BY m3.`message_id`, m3.`message_group`
                ) as grppart
                    GROUP BY grppart.`message_group`, grppart.`group_partition`
            ) AS maxed ON maxed.`message_group` = m.`message_group` AND maxed.`group_partition` = part.`group_partition`
            WHERE $conditions 
                AND m.`message_type` IN $grouped
                AND m.`message_group` IS NOT NULL
            UNION ALL
            SELECT DISTINCT m.`message_id`, '' AS grp_id, m.`message_group`, m.`message_time`, m.`message_from`, m.`message_subject`, um.`sender_id` as message_sender,
                m.`message_type`, um.`message_unread`, um.`message_favorite`, um.`message_forwarded`, m.`message_text`, m.`message_time` as LatestTimestamp
            FROM %%USERS_TO_MESSAGES%% as um
            INNER JOIN %%MESSAGES%% as m ON m.`message_id` = um.`message_id`
            WHERE $conditions
                AND ( m.`message_type` NOT IN $grouped OR m.`message_group` IS NULL )
            ORDER BY LatestTimestamp DESC, message_time DESC, message_id 
            LIMIT :offset, :limit;";

        $MessageResult = [];
        if ($MessCategory != MESSAGE_TYPE_SEARCH || !empty($searchtext)) {
            $MessageResult = $db->select($sql, $params);
        }
        $MessageResult = $this->hideReturnedFleetButtons($MessageResult);

        return ['rows' => $MessageResult, 'count' => $MessageCount, 'page' => $page, 'maxPage' => $maxPage];
    }

    /**
     * Template data of one message row: formatted time, spy report text, recipients of private messages and the
     * user ids an answer goes to.
     *
     * @param array<string, mixed> $MessageRow
     * @param list<int> $groupedArray types shown grouped
     * @return array<string, mixed>
     */
    private function describeRow(array $MessageRow, array $groupedArray): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $receiverList = [];
        $answerTo = [];
        if ($MessageRow['message_sender'] != $USER['id']) {
            $answerTo[] = $MessageRow['message_sender'];
        }
        if ($MessageRow['message_type'] == MESSAGE_TYPE_USER) {
            $sql = "SELECT u.`id`, CONCAT(u.`username`, ' [', u.`galaxy`, ':', u.`system`, ':', u.`planet`, ']') AS receiver
                    FROM %%USERS%% as u
                    INNER JOIN %%USERS_TO_MESSAGES%% um ON um.`user_id` = u.`id`
                    WHERE um.`user_id` <> um.`sender_id` AND um.`message_id` = :message_id;";
            $receiverList = $db->select($sql, [
                ':message_id' => $MessageRow['message_id'],
            ]);

            foreach ($receiverList as $receiverRow) {
                if ($receiverRow['id'] != $USER['id']) {
                    $answerTo[] = $receiverRow['id'];
                }
            }
        }
        $spyTitle = '';
        if ($MessageRow['message_type'] == MESSAGE_TYPE_SPY_REPORT) {
            $spyTitle = $this->spyReport()->getSpyReportTitle($MessageRow['message_text']);
            $MessageRow['message_text'] = $this->spyReport()->getSpyReport($MessageRow['message_text']);
        }

        if (!in_array($MessageRow['message_type'], $groupedArray)) {
            $MessageRow['message_group'] = $MessageRow['message_id'];
        }

        return [
            'id'		=> $MessageRow['message_id'],
            'time'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $MessageRow['message_time'], $USER['timezone']),
            'from'		=> $MessageRow['message_from'],
            'subject'	=> $MessageRow['message_subject'],
            'sender'	=> $MessageRow['message_sender'],
            'type'		=> $MessageRow['message_type'],
            'unread'	=> $MessageRow['message_unread'],
            'favorite'	=> $MessageRow['message_favorite'],
            'forwarded'	=> $MessageRow['message_forwarded'],
            'text'		=> $MessageRow['message_text'],
            'spyTitle'	=> $spyTitle,
            'grp_id'	=> empty($MessageRow['grp_id']) ? $MessageRow['message_id'] : $MessageRow['grp_id'],
            'group'		=> empty($MessageRow['message_group']) ? $MessageRow['message_id'] : $MessageRow['message_group'],
            'myself'	=> $MessageRow['message_sender'] == $USER['id'],
            'receiver'	=> $receiverList,
            'answerTo'	=> $answerTo,
        ];
    }

    /**
     * The list page: filtered messages with their template data, the category overview and the page window. The messages
     * on the page are marked as read.
     *
     * @param mixed $MessCategory requested category (MESSAGE_TYPE_*, -1 = ALL)
     * @param mixed $page         requested page number (clamped to the available pages)
     * @param mixed $fav          1 = only favourites
     * @param int   $grp          1 = group reports and system messages like private messages
     * @return array{MessageList: list<array<string, mixed>>, CategoryList: array<int, array<string, mixed>>, MessageCount: int|string, page: int, maxPage: int|float}
     */
    public function listPage(mixed $MessCategory, mixed $page, mixed $fav, string $searchtext, int $grp): array
    {
        $USER = $this->state->USER;
        $db = $this->db;
        $MessageList    = [];
        $MessagesID     = [];
        $CategoryList = $this->categoryList();

        [$conditions, $params] = $this->filter($MessCategory, $fav, $searchtext);
        $groupedArray = $this->groupedTypes($grp);
        $data = $this->fetchPage($conditions, $params, $groupedArray, $page, $MessCategory, $searchtext);

        foreach ($data['rows'] as $MessageRow) {
            $MessagesID[] = $MessageRow['message_id'];
            $MessageList[] = $this->describeRow($MessageRow, $groupedArray);
        }

        if (!empty($MessagesID)) {
            $sql = 'UPDATE %%USERS_TO_MESSAGES%% SET `message_unread` = 0 
                WHERE `message_id` IN (' . implode(',', $MessagesID) . ') AND `user_id` = :userID;';
            $db->update($sql, [
                ':userID'       => $USER['id'],
            ]);
        }

        return [
            'MessageList' => $MessageList,
            'CategoryList' => $CategoryList,
            'MessageCount' => $data['count'],
            'page' => $data['page'],
            'maxPage' => $data['maxPage'],
        ];
    }

    /**
     * Marketplace hold messages embed a recall form. Remove it once that fleet
     * is flying home or has already arrived and been deleted.
     */
    public function hideReturnedFleetButtons(array $messages): array
    {
        $fleetIds = [];
        foreach ($messages as $message) {
            $text = $message['message_text'] ?? '';
            if (!is_string($text) || !str_contains($text, 'sendfleetback')) {
                continue;
            }
            if (preg_match_all('/name="fleetID"\s+value="(\d+)"/', $text, $matches)) {
                foreach ($matches[1] as $fleetId) {
                    $fleetIds[(int) $fleetId] = (int) $fleetId;
                }
            }
        }

        if ($fleetIds === []) {
            return $messages;
        }

        $params = [];
        $placeholders = [];
        foreach (array_values($fleetIds) as $index => $fleetId) {
            $placeholder = ':fleetId' . $index;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $fleetId;
        }

        $fleets = $this->db->select(
            'SELECT id, mess FROM %%FLEETS%% WHERE id IN (' . implode(', ', $placeholders) . ')',
            $params,
        );
        $stateById = [];
        foreach ($fleets as $fleet) {
            $stateById[(int) $fleet['id']] = (int) $fleet['mess'];
        }

        foreach ($messages as &$message) {
            $text = $message['message_text'] ?? '';
            if (!is_string($text) || !str_contains($text, 'sendfleetback')) {
                continue;
            }
            $message['message_text'] = preg_replace_callback(
                '/<form\b[^>]*sendfleetback[^>]*>.*?<\/form>/is',
                static function (array $match) use ($stateById): string {
                    if (!preg_match('/name="fleetID"\s+value="(\d+)"/', $match[0], $idMatch)) {
                        return $match[0];
                    }
                    $fleetId = (int) $idMatch[1];
                    if (!isset($stateById[$fleetId]) || $stateById[$fleetId] === FLEET_RETURN) {
                        return '';
                    }
                    return $match[0];
                },
                $text,
            );
        }
        unset($message);

        return $messages;
    }
}
