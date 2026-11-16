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

namespace App\Controller\Admin;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\SpyReport;
use App\Game\Core\Universe;

/**
 * Message browser for admins: filter by category, sender and recipient, with delete.
 */
#[\App\Routing\GamePage('admin.php', 'messageList')]
#[\App\Routing\GamePage('admin.php', 'messageList', uniPrefix: true)]
class MessageListController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $page = $this->http()->_GP('side', 1);
        $type = $this->http()->_GP('type', MESSAGE_TYPE_ALL);
        $sender = $this->http()->_GP('sender', '', UTF8_SUPPORT);
        $receiver = $this->http()->_GP('receiver', '', UTF8_SUPPORT);
        $dateStart = $this->http()->_GP('dateStart', []);
        $dateEnd = $this->http()->_GP('dateEnd', []);

        $db = $this->db;

        $perSide = 50;

        $messageList = [];
        $userWhereSQL = '';
        $dateWhereSQL = '';
        $countJoinSQL = '';
        $params = [];

        $categories = $LNG['mg_type'];
        unset($categories[999]);

        $dateStart = array_filter($dateStart, 'is_numeric');
        $dateEnd = array_filter($dateEnd, 'is_numeric');

        $useDateStart = count($dateStart) == 3;
        $useDateEnd = count($dateEnd) == 3;

        if ($useDateStart && $useDateEnd) {
            $dateWhereSQL = ' AND um.`created_at` BETWEEN :msgTimeStart AND :msgTimeEnd';
            $params[':msgTimeStart'] = mktime(0, 0, 0, (int) $dateStart['month'], (int) $dateStart['day'], (int) $dateStart['year']);
            $params[':msgTimeEnd'] = mktime(23, 59, 59, (int) $dateEnd['month'], (int) $dateEnd['day'], (int) $dateEnd['year']);
        } elseif ($useDateStart) {
            $dateWhereSQL = ' AND um.`created_at` > :msgTime';
            $params[':msgTime'] = mktime(0, 0, 0, (int) $dateStart['month'], (int) $dateStart['day'], (int) $dateStart['year']);
        } elseif ($useDateStart) {
            $dateWhereSQL = ' AND um.`created_at` < :msgTime';
            $params[':msgTime'] = mktime(23, 59, 59, (int) $dateEnd['month'], (int) $dateEnd['day'], (int) $dateEnd['year']);
        }

        if (!empty($sender)) {
            $countJoinSQL .= ' LEFT JOIN %%USERS%% as us ON m.`message_sender` = us.`id`';
            $userWhereSQL .= ' AND us.`username` = :sender';
            $params[':sender'] = $sender;
        }

        if (!empty($receiver)) {
            $countJoinSQL .= ' LEFT JOIN %%USERS%% as u ON um.`user_id` = u.`id`';
            $userWhereSQL .= ' AND u.`username` = :receiver';
            $params[':receiver'] = $receiver;
        }

        $params[':universe'] = $this->universe()->getEmulated();

        if ($type != MESSAGE_TYPE_ALL) {
            $sql = "SELECT COUNT(*) AS anz FROM %%MESSAGES%% AS m 
            LEFT JOIN %%USERS_TO_MESSAGES%% as um ON m.`message_id` = um.`message_id` 
            $countJoinSQL 
            WHERE m.`message_type` = :type AND m.`message_universe` = :universe $dateWhereSQL $userWhereSQL;";
            $params[':type'] = $type;
            $MessageCount = $db->selectSingle($sql, $params, 'anz');
        } else {
            $sql = "SELECT COUNT(*) AS anz FROM %%MESSAGES%% AS m 
            LEFT JOIN %%USERS_TO_MESSAGES%% as um ON m.`message_id` = um.`message_id` 
            $countJoinSQL 
            WHERE m.`message_universe` = :universe $dateWhereSQL $userWhereSQL;";
            $MessageCount = $db->selectSingle($sql, $params, 'anz');
        }

        $maxPage = max(1, ceil($MessageCount / $perSide));
        $page = max(1, min($page, $maxPage));

        $sqlLimit = (($page - 1) * $perSide) . ", " . ($perSide - 1);

        if ($type != MESSAGE_TYPE_ALL) {
            $params[':type'] = $type;
            $messageRaw = $db->select(
                "SELECT m.`message_id`, u.`username`, us.`username` as senderName, m.`message_from`, 
                m.`message_sender`, um.`user_id` AS message_owner, m.`message_subject`, m.`message_text`, m.`message_type`, um.`deleted_at` AS message_deleted, um.`created_at` AS message_time
		    FROM %%MESSAGES%% as m
            LEFT JOIN %%USERS_TO_MESSAGES%% as um ON m.`message_id` = um.`message_id`
		    LEFT JOIN %%USERS%% as u ON um.`user_id` = u.`id`
		    LEFT JOIN %%USERS%% as us ON m.`message_sender` = us.`id`
            WHERE m.`message_type` = :type AND message_universe = :universe $dateWhereSQL $userWhereSQL
            ORDER BY m.`message_time` DESC, m.`message_id` DESC
            LIMIT $sqlLimit;",
                $params,
            );
        } else {
            $messageRaw = $db->select(
                "SELECT m.`message_id`, u.`username`, us.`username` as senderName, m.`message_from`, 
                m.`message_sender`, um.`user_id` AS message_owner, m.`message_subject`, m.`message_text`, m.`message_type`, um.`deleted_at` AS message_deleted, um.`created_at` as message_time
            FROM %%MESSAGES%% as m 
            LEFT JOIN %%USERS_TO_MESSAGES%% as um ON m.`message_id` = um.`message_id`
            LEFT JOIN %%USERS%% as u ON um.`user_id` = u.`id` 
            LEFT JOIN %%USERS%% as us ON m.`message_sender` = us.`id`
            WHERE m.`message_universe` = :universe $dateWhereSQL $userWhereSQL
            ORDER BY m.`message_time` DESC, m.`message_id` DESC
            LIMIT $sqlLimit;",
                $params,
            );
        }

        foreach ($messageRaw as $messageRow) {
            if ($messageRow['message_type'] == MESSAGE_TYPE_SPY_REPORT) {
                $messageRow['message_text'] = $this->spyReport()->getSpyReport($messageRow['message_text']);
            }

            $messageList[$messageRow['message_id']] = [
                'sender'   => empty($messageRow['senderName']) ? $messageRow['message_from'] : $messageRow['senderName'] . ' (ID:&nbsp;' . $messageRow['message_sender'] . ')',
                'receiver' => $messageRow['username'] . ' (ID:&nbsp;' . $messageRow['message_owner'] . ')',
                'subject'  => $messageRow['message_subject'],
                'text'     => $messageRow['message_text'],
                'type'     => $messageRow['message_type'],
                'deleted'  => $messageRow['message_deleted'] != null,
                'time'     => str_replace(' ', '&nbsp;', $this->viewHelpers()->formatDate($LNG['php_tdformat'], $messageRow['message_time'], $USER['timezone'])),
            ];
        }

        $this->assign([
            'categories'   => $categories,
            'maxPage'      => $maxPage,
            'page'         => $page,
            'messageList'  => $messageList,
            'type'         => $type,
            'dateStart'    => $dateStart,
            'dateEnd'      => $dateEnd,
            'sender'       => $sender,
            'receiver'     => $receiver,
            'Selected'     => 0,
        ]);

        return $this->render('admin/MessageList.html.twig');
    }
}
