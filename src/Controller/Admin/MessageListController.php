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

use Doctrine\DBAL\Query\QueryBuilder;
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
    /**
     * Message browser (50 per page). Request: side (page), type (message category, default all), sender and receiver (name
     * fragments), dateStart/dateEnd (arrays day, month, year; both give a range, only a start means "since"). Reads MESSAGES via
     * USERS_TO_MESSAGES for the emulated universe.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $page = $this->http()->_GP('side', 1);
        $type = $this->http()->_GP('type', MESSAGE_TYPE_ALL);
        $sender = $this->http()->_GP('sender', '', UTF8_SUPPORT);
        $receiver = $this->http()->_GP('receiver', '', UTF8_SUPPORT);
        $dateStart = $this->http()->_GP('dateStart', []);
        $dateEnd = $this->http()->_GP('dateEnd', []);

        $perSide = 50;

        $categories = $LNG['mg_type'];
        unset($categories[999]);

        $dateStart = array_filter($dateStart, 'is_numeric');
        $dateEnd = array_filter($dateEnd, 'is_numeric');

        $filter = $this->messageFilter($type, $this->dateFilter($dateStart, $dateEnd), $sender, $receiver);

        $MessageCount = $this->countMessages($filter, $sender, $receiver);

        $maxPage = max(1, ceil($MessageCount / $perSide));
        $page = max(1, min($page, $maxPage));

        $messageList = $this->messageRows($filter, $page, $perSide);

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

    /**
     * Date condition of the list: both dates (day, month, year each) give a range from the start of the first to the
     * end of the last day, only a start date means "since".
     *
     * @return array{0: string, 1: array<string, int|false>}|null condition and bound parameters, null without date filter
     */
    private function dateFilter(array $dateStart, array $dateEnd): ?array
    {
        $useDateStart = count($dateStart) == 3;
        $useDateEnd = count($dateEnd) == 3;

        // optional filters as [condition, parameters]; the same set limits the count and the page query
        $dateFilter = null;
        if ($useDateStart && $useDateEnd) {
            $dateFilter = ['um.`created_at` BETWEEN :msgTimeStart AND :msgTimeEnd', [
                'msgTimeStart' => mktime(0, 0, 0, (int) $dateStart['month'], (int) $dateStart['day'], (int) $dateStart['year']),
                'msgTimeEnd' => mktime(23, 59, 59, (int) $dateEnd['month'], (int) $dateEnd['day'], (int) $dateEnd['year']),
            ]];
        } elseif ($useDateStart) {
            $dateFilter = ['um.`created_at` > :msgTime', [
                'msgTime' => mktime(0, 0, 0, (int) $dateStart['month'], (int) $dateStart['day'], (int) $dateStart['year']),
            ]];
        }

        return $dateFilter;
    }

    /**
     * Builds the shared condition adder: message type (unless all types), universe, date range, sender and receiver
     * name. The sender/receiver joins (aliases us/u) must already be on the query it is applied to.
     *
     * @return \Closure(QueryBuilder): QueryBuilder
     */
    private function messageFilter(mixed $type, ?array $dateFilter, mixed $sender, mixed $receiver): \Closure
    {
        $filter = function (QueryBuilder $qb) use ($type, $dateFilter, $sender, $receiver): QueryBuilder {
            if ($type != MESSAGE_TYPE_ALL) {
                $qb->andWhere('m.`message_type` = :type')->setParameter('type', $type);
            }
            $qb->andWhere('m.`message_universe` = :universe')->setParameter('universe', $this->universe()->getEmulated());
            // staff boundary: no messages owned or sent by staff the actor may not act on (own messages stay visible)
            $usersTable = $this->db->table('USERS');
            foreach (['um.`user_id`' => 'ownerStaff', 'm.`message_sender`' => 'senderStaff'] as $column => $alias) {
                $qb->andWhere('NOT EXISTS (SELECT 1 FROM ' . $usersTable . ' ' . $alias . ' WHERE ' . $alias . '.`id` = ' . $column
                    . ' AND ' . $alias . '.`id` <> :actorId AND (' . $alias . '.`id` = :rootUser OR ' . $alias . '.`authlevel` >= :actorLevel))');
            }
            $qb->setParameter('actorId', (int) $this->state->USER['id'])
                ->setParameter('rootUser', ROOT_USER)
                ->setParameter('actorLevel', (int) $this->state->USER['authlevel']);
            if ($dateFilter !== null) {
                $qb->andWhere($dateFilter[0]);
                foreach ($dateFilter[1] as $name => $value) {
                    $qb->setParameter($name, $value);
                }
            }
            if (!empty($sender)) {
                $qb->andWhere('us.`username` = :sender')->setParameter('sender', $sender);
            }
            if (!empty($receiver)) {
                $qb->andWhere('u.`username` = :receiver')->setParameter('receiver', $receiver);
            }

            return $qb;
        };

        return $filter;
    }

    /**
     * Counts the messages matching the filter (joins the sender/receiver users only when they are filtered on).
     */
    private function countMessages(\Closure $filter, mixed $sender, mixed $receiver): mixed
    {
        $db = $this->db;

        $countQuery = $db->qb()
            ->select('COUNT(*) AS anz')
            ->from($db->table('MESSAGES'), 'm')
            ->leftJoin('m', $db->table('USERS_TO_MESSAGES'), 'um', 'm.`message_id` = um.`message_id`');
        if (!empty($sender)) {
            $countQuery->leftJoin('m', $db->table('USERS'), 'us', 'm.`message_sender` = us.`id`');
        }
        if (!empty($receiver)) {
            $countQuery->leftJoin('um', $db->table('USERS'), 'u', 'um.`user_id` = u.`id`');
        }
        $MessageCount = $db->fetchValue($filter($countQuery));

        return $MessageCount;
    }

    /**
     * One page of messages (newest first; the page holds `$perSide - 1` rows, long-standing behaviour of this list)
     * with sender/receiver labels, spy reports rendered and the local time.
     *
     * @return array<int, array<string, mixed>> keyed by message id
     */
    private function messageRows(\Closure $filter, int|float $page, int $perSide): array
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $messageList = [];

        // the page holds $perSide - 1 rows (long-standing behaviour of this list)
        $messageRaw = $db->fetchAll(
            $filter(
                $db->qb()
                    ->select(
                        'm.`message_id`',
                        'u.`username`',
                        'us.`username` AS senderName',
                        'm.`message_from`',
                        'm.`message_sender`',
                        'um.`user_id` AS message_owner',
                        'm.`message_subject`',
                        'm.`message_text`',
                        'm.`message_type`',
                        'um.`deleted_at` AS message_deleted',
                        'um.`created_at` AS message_time',
                    )
                    ->from($db->table('MESSAGES'), 'm')
                    ->leftJoin('m', $db->table('USERS_TO_MESSAGES'), 'um', 'm.`message_id` = um.`message_id`')
                    ->leftJoin('um', $db->table('USERS'), 'u', 'um.`user_id` = u.`id`')
                    ->leftJoin('m', $db->table('USERS'), 'us', 'm.`message_sender` = us.`id`'),
            )
                ->orderBy('m.`message_time`', 'DESC')
                ->addOrderBy('m.`message_id`', 'DESC')
                ->setFirstResult(($page - 1) * $perSide)
                ->setMaxResults($perSide - 1),
        );

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

        return $messageList;
    }
}
