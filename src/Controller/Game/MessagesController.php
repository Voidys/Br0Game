<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\AccountUtil;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\Discord;
use App\Game\Core\HTTP;
use App\Game\Core\MarketManager;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\SpyReport;
use App\Game\Core\Universe;

/**
 * Message center: category list, paged message list (grouped, favourites, search), mark/delete actions, and the write and forward popups.
 */
#[\App\Routing\GamePage('game.php', 'messages')]
#[\App\Routing\GamePage('game.php', 'messages', uniPrefix: true)]
class MessagesController extends AbstractGameController
{
    public static $requireModule = 'MODULE_MESSAGES';

    public function initialize(): void
    {
        parent::initialize();
    }

    private function sendData($Code, $Message)
    {
        $this->sendJSON([
            'code' => $Code,
            'mess' => $Message,
        ]);
    }

    public function favMessage()
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $db = $this->db;

        $this->setWindow('ajax');

        $favMessID = $this->http()->_GP('favMessID', 0);

        if (empty($favMessID)) {
            $this->sendData(0, $LNG['error']);
        }

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
            $this->sendData(0, 'OK');
        } else {
            $sql = "UPDATE %%USERS_TO_MESSAGES%% SET `message_favorite` = 1 
                WHERE `message_id` = :messID AND `user_id` = :userID;";
            $db->update($sql, [
                ':messID'   => $favMessID,
                ':userID'   => $USER['id'],
            ]);
            $this->sendData(1, 'OK');
        }
    }

    public function deleteMessage()
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $db = $this->db;

        $this->setWindow('ajax');

        $delMessID  = $this->http()->_GP('delMessID', 0);

        if (empty($delMessID)) {
            $this->sendData(0, $LNG['error']);
        }

        $sql = "UPDATE %%USERS_TO_MESSAGES%% SET `deleted_at` = :timestamp 
            WHERE `message_id` = :messID AND `user_id` = :userID;";
        $db->update($sql, [
            ':timestamp'    => $this->state->runtime->timestamp,
            ':messID'       => $delMessID,
            ':userID'       => $USER['id'],
        ]);

        $this->sendData($delMessID, $LNG['mg_deleted']);
    }

    public function action()
    {
        $USER = & $this->state->USER;

        $db = $this->db;

        $MessCategory  	= $this->http()->_GP('messcat', MESSAGE_TYPE_ALL);
        $page		 	= $this->http()->_GP('site', 1);
        $messageIDs		= $this->http()->_GP('messageID', []);
        $action	        = $this->http()->_GP('action', "");
        $redirectUrl	= 'game?page=messages&category=' . $MessCategory . '&site=' . $page;

        if ($action == "") {
            $this->redirectTo($redirectUrl);
        }

        if ($action == 'deleteunmarked' && empty($messageIDs)) {
            $action	= 'deletetypeall';
        }

        if ($action == 'deletetypeall' && $MessCategory == MESSAGE_TYPE_ALL) {
            $action	= 'deleteall';
        }

        if ($action == 'readtypeall' && $MessCategory == MESSAGE_TYPE_ALL) {
            $action	= 'readall';
        }

        switch ($action) {
            case 'readall':
                $sql = "UPDATE %%USERS_TO_MESSAGES%% 
                    SET `message_unread` = 0 WHERE `user_id` = :userID;";
                $db->update($sql, [
                    ':userID'   => $USER['id'],
                ]);
                break;
            case 'readtypeall':
                $sql = "UPDATE %%USERS_TO_MESSAGES%% um
                    INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                    SET um.`message_unread` = 0
                    WHERE um.`user_id` = :userID
                      AND m.`message_type` = :messCategory;";
                $db->update($sql, [
                    ':userID'       => $USER['id'],
                    ':messCategory' => $MessCategory,
                ]);
                break;
            case 'readmarked':
                if (empty($messageIDs)) {
                    $this->redirectTo($redirectUrl);
                }

                $messageIDs = array_filter(
                    $messageIDs,
                    static fn($key) => ctype_digit((string) $key) && (int) $key > 0,
                    ARRAY_FILTER_USE_KEY,
                );

                if (empty($messageIDs)) {
                    $this->redirectTo($redirectUrl);
                }

                $sql = 'UPDATE %%USERS_TO_MESSAGES%%
                    SET `message_unread` = 0 
                    WHERE `user_id` = :userID
                      AND `message_id` IN (' . implode(', ', array_keys($messageIDs)) . ');';
                $db->update($sql, [
                    ':userID'       => $USER['id'],
                ]);
                break;
            case 'deleteall':
                $sql = "UPDATE %%USERS_TO_MESSAGES%%
                    SET `deleted_at` = :timestamp 
                    WHERE `user_id` = :userID
                      AND `message_favorite` = 0;";
                $db->update($sql, [
                    ':timestamp'    => $this->state->runtime->timestamp,
                    ':userID'       => $USER['id'],
                ]);
                break;
            case 'deletetypeall':
                $sql = "UPDATE %%USERS_TO_MESSAGES%% um
                    INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                    SET um.`deleted_at` = :timestamp
                    WHERE um.`user_id` = :userID
                      AND m.`message_type` = :messCategory
                      AND um.`message_favorite` = 0;";
                $db->update($sql, [
                    ':timestamp' => $this->state->runtime->timestamp,
                    ':userID' => $USER['id'],
                    ':messCategory' => $MessCategory,
                ]);
                break;
            case 'deletemarked':
                if (empty($messageIDs)) {
                    $this->redirectTo($redirectUrl);
                }

                $messageIDs = array_filter(
                    $messageIDs,
                    static fn($key) => ctype_digit((string) $key) && (int) $key > 0,
                    ARRAY_FILTER_USE_KEY,
                );

                if (empty($messageIDs)) {
                    $this->redirectTo($redirectUrl);
                }

                $sql = 'UPDATE %%USERS_TO_MESSAGES%%
                    SET `deleted_at` = :timestamp 
                    WHERE `user_id` = :userId
                      AND `message_favorite` = 0
                      AND `message_id` IN (' . implode(', ', array_keys($messageIDs)) . ');';
                $db->update($sql, [
                    ':timestamp' => $this->state->runtime->timestamp,
                    ':userId' => $USER['id'],
                ]);
                break;
            case 'deleteunmarked':
                if (empty($messageIDs) || !is_array($messageIDs)) {
                    $this->redirectTo($redirectUrl);
                }

                $messageIDs = array_filter(
                    $messageIDs,
                    static fn($key) => ctype_digit((string) $key) && (int) $key > 0,
                    ARRAY_FILTER_USE_KEY,
                );

                if (empty($messageIDs)) {
                    $this->redirectTo($redirectUrl);
                }

                $sql = 'UPDATE %%USERS_TO_MESSAGES%% um
                    INNER JOIN %%MESSAGES%% m ON m.`message_id` = um.`message_id`
                    SET um.`deleted_at` = :timestamp
                    WHERE um.`user_id` = :userId
                      AND um.`message_favorite` = 0
                      AND um.`message_id` NOT IN (' . implode(', ', array_keys($messageIDs)) . ')';
                if ($MessCategory != MESSAGE_TYPE_ALL) {
                    $sql .= ' AND m.`message_type` = :messCategory;';
                    $db->update($sql, [
                        ':timestamp'    => $this->state->runtime->timestamp,
                        ':userId'       => $USER['id'],
                        ':messCategory' => $MessCategory,
                    ]);
                } else {
                    $sql .= ';';
                    $db->update($sql, [
                        ':timestamp' => $this->state->runtime->timestamp,
                        ':userId'    => $USER['id'],
                    ]);
                }
                break;
        }
        $this->redirectTo($redirectUrl);
    }

    public function loadUser()
    {
        $db = $this->db;
        $universe = $this->universe()->current();
        $searchtext		= $this->http()->_GP('searchtext', '', true);
        if (empty($searchtext) || strlen($searchtext) < 3) {
            $this->sendJSON([]);
        }

        $sql = "SELECT u.`id`, u.`username` FROM %%USERS%% u
            INNER JOIN %%USERS_SETTINGS%% us ON u.`id` = us.`user_id`
            WHERE u.`username` LIKE :searchtext 
              AND u.`universe` = :universe 
              AND us.`settings_blockPM` = 0;";

        $users = $db->select($sql, [
            ':searchtext'   => '%' . $searchtext . '%',
            ':universe'     => $universe,
        ]);

        $this->sendJSON($users);
    }

    /** the recipient exists in the current universe and has not disabled private messages */
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

    public function sendForward()
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $receiver   = $this->http()->_GP('to', []);
        $messageID  = $this->http()->_GP('id', 0);

        if (empty($receiver) || empty($messageID)) {
            $this->sendJSON($LNG['mg_error']);
        }

        $db = $this->db;
        $sql = 'SELECT COUNT(*) as state FROM %%USERS_TO_MESSAGES%%
            WHERE `user_id` = :userId
              AND `message_id` = :messageId
              AND `deleted_at` IS NULL;';
        $ownsMessage = $db->selectSingle($sql, [
            ':userId'    => $USER['id'],
            ':messageId' => $messageID,
        ], 'state');

        if (empty($ownsMessage)) {
            $this->sendJSON($LNG['mg_error']);
        }

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

        if (empty($receiverList)) {
            $this->sendJSON($LNG['mg_error']);
        }

        $this->playerUtil()->forwardMessage($receiverList, $USER['id'], $messageID);

        $this->sendJSON($LNG['mg_message_send']);
    }

    public function send()
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;
        $receiver   = $this->http()->_GP('to', []);
        $subject    = $this->http()->_GP('subject', $LNG['mg_no_subject'], true);
        $text       = $this->http()->_GP('text', '', true);
        $groupKey   = $this->http()->_GP('groupid', "", true);
        $senderName	= $USER['username'] . ' [' . $USER['galaxy'] . ':' . $USER['system'] . ':' . $USER['planet'] . ']';

        $text		= makebr($text);

        if (empty($receiver) || empty($text)) {
            $this->sendJSON($LNG['mg_error']);
        }

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

        if (empty($receiverList)) {
            $this->sendJSON($LNG['mg_blocked']);
        }

        $db = $this->db;
        $config = $this->configs->get();
        $sql = "SELECT `text` FROM %%MESSAGES_FORBIDDEN%%";
        $texts = $db->select($sql);
        $banned = false;
        if (!empty($texts) && $USER['authlevel'] == AUTH_USR) {
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

        $this->sendJSON($LNG['mg_message_send']);
    }

    public function forward(): Response
    {
        $this->setWindow('popup');

        $messageID  = $this->http()->_GP('id', 0);

        $this->addScript('message.js');
        $this->assign([
            'messageID' => $messageID,
            'action' => 'forward',
        ]);

        return $this->render('game/page.messages.write.html.twig');
    }

    public function write(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $this->setWindow('popup');

        $db = $this->db;

        $receivers      = $this->http()->_GP('receivers', [], true);
        $Subject        = $this->http()->_GP('subject', $LNG['mg_no_subject'], true);
        $Message        = $this->http()->_GP('message', "", true);
        $messageGroup   = $this->http()->_GP('groupid', "", true);

        if (!is_array($receivers)) {
            $receivers = [$receivers];
        }

        $sql = "SELECT p.`galaxy`, p.`system`, p.`planet`, u.`id`, u.`username`, u.`id_planet`, us.`settings_blockPM`
            FROM %%PLANETS%% as p, %%USERS%% as u 
            INNER JOIN %%USERS_SETTINGS%% us on us.`user_id` = u.`id` 
            WHERE u.`id` = :receiverId AND p.`id` = u.`id_planet`;";

        $receiverList = [];
        foreach ($receivers as $receiver) {
            $receiverRecord = $db->selectSingle($sql, [
                ':receiverId'   => $receiver,
            ]);
            $receiverList[$receiverRecord['id']] = $receiverRecord;
        }

        $this->addScript('message.js');
        $this->assign([
            'subject'       => $Subject,
            'receiverList'  => $receiverList,
            'message'       => $Message,
            'messageGroup'  => $messageGroup,
            'action' => 'write',
        ]);

        return $this->render('game/page.messages.write.html.twig');
    }

    /**
     * Marketplace hold messages embed a recall form. Remove it once that fleet
     * is flying home or has already arrived and been deleted.
     */
    private function hideReturnedFleetButtons(array $messages): array
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

    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $MessCategory = $this->http()->_GP('category', -1);
        $page = $this->http()->_GP('site', 1);
        $fav = $this->http()->_GP('fav', 0);
        $searchtext = $this->http()->_GP('searchtext', '', true);

        $grp = isset($this->state->requestCookies['msgGrouping']) ? (int) $this->state->requestCookies['msgGrouping'] : 0;

        $db = $this->db;

        $fleetIntoDebris = $this->configs->get()->fleet_debris_percentage;
        $defIntoDebris = $this->configs->get()->def_debris_percentage;
        $uniType = $this->configs->get()->uni_type;
        $maxGalaxy = $this->configs->get()->max_galaxy;

        $stbSettings2 = $this->playerUtil()->player_stb_settings($USER);

        $combustion_tech = $USER["combustion_tech"];
        $impulse_motor_tech = $USER["impulse_motor_tech"];

        $MessageList    = [];
        $MessagesID     = [];
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
        $Total = [
            MESSAGE_TYPE_SPY_REPORT         => 0,
            MESSAGE_TYPE_SPY_DEFENCE        => 0,
            MESSAGE_TYPE_USER               => 0,
            MESSAGE_TYPE_ALLIANCE           => 0,
            MESSAGE_TYPE_BATTLE_REPORT      => 0,
            MESSAGE_TYPE_SYSTEM             => 0,
            MESSAGE_TYPE_TRANSPORT          => 0,
            MESSAGE_TYPE_FOREIGN_TRANSPORT  => 0,
            MESSAGE_TYPE_EXPEDITION         => 0,
            MESSAGE_TYPE_GAME               => 0,
            MESSAGE_TYPE_BUILD_LIST         => 0,
            MESSAGE_TYPE_ALL                => 0,
            MESSAGE_TYPE_FAVORITES          => 0,
            MESSAGE_TYPE_SEARCH             => 0,
        ];
        $UnRead = [
            MESSAGE_TYPE_SPY_REPORT         => 0,
            MESSAGE_TYPE_SPY_DEFENCE        => 0,
            MESSAGE_TYPE_USER               => 0,
            MESSAGE_TYPE_ALLIANCE           => 0,
            MESSAGE_TYPE_BATTLE_REPORT      => 0,
            MESSAGE_TYPE_SYSTEM             => 0,
            MESSAGE_TYPE_TRANSPORT          => 0,
            MESSAGE_TYPE_FOREIGN_TRANSPORT  => 0,
            MESSAGE_TYPE_EXPEDITION         => 0,
            MESSAGE_TYPE_GAME               => 0,
            MESSAGE_TYPE_BUILD_LIST         => 0,
            MESSAGE_TYPE_ALL                => 0,
            MESSAGE_TYPE_FAVORITES          => 0,
            MESSAGE_TYPE_SEARCH             => 0,
        ];

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

        foreach ($MessageResult as $MessageRow) {
            $MessagesID[] = $MessageRow['message_id'];

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

            $MessageList[]	= [
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

        if (!empty($MessagesID)) {
            $sql = 'UPDATE %%USERS_TO_MESSAGES%% SET `message_unread` = 0 
                WHERE `message_id` IN (' . implode(',', $MessagesID) . ') AND `user_id` = :userID;';
            $db->update($sql, [
                ':userID'       => $USER['id'],
            ]);
        }

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $refrates = $pMarket->getReferenceRatios();
        $this->addScript('message.js');
        $this->assign([
            'MessID'		                => $MessCategory,
            'MessageCount'	                => $MessageCount,
            'MessageList'	                => $MessageList,
            'CategoryList'	                => $CategoryList,
            'page'			                => $page,
            'maxPage'		                => $maxPage,
            'fav'			                => $fav,
            'grp'			                => $grp,
            'searchtext'	                => $searchtext,
            'stb_big_time'                  => $stbSettings2['stb_big_time'],
            'stb_med_time'                  => $stbSettings2['stb_med_time'],
            'stb_small_time'                => $stbSettings2['stb_small_time'],
            'stb_big_ress'                  => $stbSettings2['stb_big_ress'],
            'stb_med_ress'                  => $stbSettings2['stb_med_ress'],
            'stb_small_ress'                => $stbSettings2['stb_small_ress'],
            'refratesMetal'                 => $refrates['metal'],
            'refratesCrystal'               => $refrates['crystal'],
            'refratesDeuterium'             => $refrates['deuterium'],
            'impulse_motor_tech'            => $impulse_motor_tech,
            'combustion_tech'               => $combustion_tech,
            'fleetIntoDebris'               => $fleetIntoDebris,
            'defIntoDebris'                 => $defIntoDebris,
            'uniType'                       => $uniType,
            'maxGalaxy'                     => $maxGalaxy,
            'spyReportsCollapsed'           => $USER['spyReportsCollapsed'],
        ]);

        return $this->render('game/page.messages.default.html.twig');
    }
}
