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
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\Discord;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\SupportTickets;
use App\Game\Core\Universe;

/**
 * Support ticket overview and ticket view/answer for admins.
 */
#[\App\Routing\GamePage('admin.php', 'support')]
#[\App\Routing\GamePage('admin.php', 'support', uniPrefix: true)]
class SupportController extends AbstractAdminController
{
    private $ticketObj;

    /**
     * Creates the SupportTickets helper after the permission check.
     */
    protected function construct(): void
    {
        parent::construct();

        $this->ticketObj    = new SupportTickets($this->db, $this->configs, $this->state);
    }

    /**
     * Ticket overview of the emulated universe with answer counts. `mode` other than show dispatches to the method of that name (view, send).
     */
    public function show(): Response
    {
        //TODO: temporary fix for 2Moons 1.7TO1.6 PageClass Wrapper
        $ACTION = $this->http()->_GP('mode', 'show');
        if (in_array($ACTION, ['view', 'send'], true)) {
            return $this->{$ACTION}();
        }

        $LNG = $this->state->LNG;
        $db = $this->db;

        $sql = 'SELECT t.*, u.`username`, COUNT(a.`ticketID`) as answer
            FROM %%TICKETS%% t
            INNER JOIN %%TICKETS_ANSWER%% a USING (`ticketID`)
            LEFT JOIN %%USERS%% u ON u.`id` = t.`ownerID`
            WHERE t.`universe` = :universe
            GROUP BY a.`ticketID` ORDER BY t.`ticketID` DESC;';
        $ticketResult = $db->select($sql, [
            ':universe' => $this->universe()->getEmulated(),
        ]);
        $ticketList = [];

        foreach ($ticketResult as $ticketRow) {
            $ticketRow['time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $ticketRow['time'], $this->state->USER['timezone']);
            $ticketList[$ticketRow['ticketID']]	= $ticketRow;
        }

        $categories = [
            0 => $LNG['adm_ti_all'],
            1 => $LNG['adm_ti_user_tickets'],
            9 => $LNG['adm_ti_debug_errors'],
        ];

        $this->assign([
            'ticketList' => $ticketList,
            'categories' => $categories,
        ]);

        return $this->render('admin/page.ticket.default.html.twig');
    }

    /**
     * Answers a ticket (mode=send): request id, message and change_status. change_status toggles open (1) and closed (2); an answer and/or
     * the status change is stored through SupportTickets::createAnswer, the Discord ticket hook is notified and the ticket owner gets an
     * in-game message. Redirects to the ticket, or to the overview after closing.
     */
    public function send(): never
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;
        $config = $this->configs->get();
        $ticketID = $this->http()->_GP('id', 0);
        $message = $this->http()->_GP('message', '', true);
        $change = $this->http()->_GP('change_status', 0);

        $sql = 'SELECT `ownerID`, `subject`, `status` 
            FROM %%TICKETS%% 
            WHERE `ticketID` = :ticketID;';
        $ticketDetail = $db->selectSingle($sql, [
            ':ticketID' => $ticketID,
        ]);

        $status = ($change ? ($ticketDetail['status'] <= 1 ? 2 : 1) : 1);

        if (!$change && empty($message)) {
            $this->http()->redirectTo('admin?page=support&mode=view&id=' . $ticketID);
        }

        $subject = "RE: " . $ticketDetail['subject'];

        if ($change && $status == 1) {
            $this->ticketObj->createAnswer($ticketID, $USER['id'], $USER['username'], $subject, $LNG['ti_admin_open'], $status);
        }

        if (!empty($message)) {
            $this->ticketObj->createAnswer($ticketID, $USER['id'], $USER['username'], $subject, $message, $status);

            if (isset($config->discord_tickets_hook) && !empty($config->discord_tickets_hook)) {
                $this->discord()->sendMessage($config->discord_tickets_hook, 'Admin answer', [
                    'Universe' => $this->universe()->current(),
                    'Ticket-ID' => '[' . $ticketID . '](' . $this->state->runtime->protocol . $this->state->runtime->httpHost . '/admin?page=support&mode=view&id=' . $ticketID . ')',
                    'User' => $USER['username'] . ' (' . $USER['id'] . ')',
                    'Subject' => $subject,
                    'Message' => $message,
                ]);
            }
        }

        if ($change && $status == 2) {
            $this->ticketObj->createAnswer($ticketID, $USER['id'], $USER['username'], $subject, $LNG['ti_admin_close'], $status);

            if (isset($config->discord_tickets_hook) && !empty($config->discord_tickets_hook)) {
                $this->discord()->sendMessage($config->discord_tickets_hook, 'Ticket Closed', [
                    'Universe' => $this->universe()->current(),
                    'Ticket-ID' => '[' . $ticketID . '](' . $this->state->runtime->protocol . $this->state->runtime->httpHost . '/admin?page=support&mode=view&id=' . $ticketID . ')',
                    'User' => $USER['username'] . ' (' . $USER['id'] . ')',
                    'Subject' => $subject,
                ]);
            }
        }

        $subject = $LNG->format('sp_answer_message_title', $ticketID);
        $text = $LNG->format('sp_answer_message', $ticketID);

        $this->playerUtil()->sendMessage(
            $ticketDetail['ownerID'],
            $USER['id'],
            $USER['username'],
            MESSAGE_TYPE_SYSTEM,
            $subject,
            $text,
            $this->state->runtime->timestamp,
            1,
            $this->universe()->getEmulated(),
        );

        $this->http()->redirectTo('admin?page=support');
    }

    /**
     * One ticket (request: id) with the conversation (TICKETS_ANSWER).
     */
    public function view(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $ticketID = $this->http()->_GP('id', 0);

        $sql = 'SELECT a.*, t.`categoryID`, t.`status`
            FROM %%TICKETS_ANSWER%% a
            INNER JOIN %%TICKETS%% t USING(`ticketID`)
            WHERE a.`ticketID` = :ticketID 
            ORDER BY a.`answerID`;';
        $answerResult = $db->select($sql, [
            ':ticketID' => $ticketID,
        ]);
        $answerList = [];

        $ticket_status = 0;

        foreach ($answerResult as $answerRow) {
            if (empty($ticket_status)) {
                $ticket_status = $answerRow['status'];
            }

            $answerRow['time']	= $this->viewHelpers()->formatDate($LNG['php_tdformat'], $answerRow['time'], $USER['timezone']);

            $answerList[$answerRow['answerID']]	= $answerRow;
        }

        $categoryList = $this->ticketObj->getCategoryList();

        $this->assign([
            'ticketID'		=> $ticketID,
            'ticket_status' => $ticket_status,
            'categoryList'	=> $categoryList,
            'answerList'	=> $answerList,
        ]);

        return $this->render('admin/page.ticket.view.html.twig');
    }
}
