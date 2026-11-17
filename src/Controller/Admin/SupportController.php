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

use App\Game\Enum\MessageType;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Discord;
use App\Game\Core\SupportTickets;
use App\Game\Core\Universe;

/**
 * Support ticket overview and ticket view/answer for admins.
 */
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
     * Ticket overview of the emulated universe with answer counts.
     */
    #[GameRoute('admin', 'admin/support', 'support')]
    public function show(): Response
    {
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

        return $this->render('admin/page.ticket.default.html.twig', [
            'ticketList' => $ticketList,
            'categories' => $categories,
        ]);
    }

    /**
     * Answers a ticket: request id, message and change_status. change_status toggles open (1) and closed (2); an answer and/or
     * the status change is stored through SupportTickets::createAnswer, the Discord ticket hook is notified and the ticket owner gets an
     * in-game message. Redirects to the ticket, or to the overview after closing.
     */
    #[GameRoute('admin', 'admin/support/reply', 'support', 'send', ['POST'])]
    public function send(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;
        $config = $this->configs->get();
        $ticketID = $this->input()->int('id');
        $message = $this->input()->string('message', '', true);
        $change = $this->input()->int('change_status');

        $sql = 'SELECT `ownerID`, `subject`, `status` 
            FROM %%TICKETS%% 
            WHERE `ticketID` = :ticketID;';
        $ticketDetail = $db->selectSingle($sql, [
            ':ticketID' => $ticketID,
        ]);

        $status = ($change ? ($ticketDetail['status'] <= 1 ? 2 : 1) : 1);

        if (!$change && empty($message)) {
            return $this->redirectToRoute('admin_support_view', ['id' => $ticketID]);
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
                    'Ticket-ID' => '[' . $ticketID . '](' . $this->routeUrl('admin_support_view', ['id' => $ticketID]) . ')',
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
                    'Ticket-ID' => '[' . $ticketID . '](' . $this->routeUrl('admin_support_view', ['id' => $ticketID]) . ')',
                    'User' => $USER['username'] . ' (' . $USER['id'] . ')',
                    'Subject' => $subject,
                ]);
            }
        }

        $subject = $LNG->format('sp_answer_message_title', $ticketID);
        $text = $LNG->format('sp_answer_message', $ticketID);

        $this->playerMessenger()->sendMessage(
            $ticketDetail['ownerID'],
            $USER['id'],
            $USER['username'],
            MessageType::System->value,
            $subject,
            $text,
            $this->state->runtime->timestamp,
            1,
            $this->universe()->getEmulated(),
        );

        return $this->redirectToRoute('admin_support_show');
    }

    /**
     * One ticket (request: id) with the conversation (TICKETS_ANSWER).
     */
    #[GameRoute('admin', 'admin/support/{id}', 'support', 'view', requirements: ['id' => '\d+'])]
    public function view(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $ticketID = $this->input()->int('id');

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

        return $this->render('admin/page.ticket.view.html.twig', [
            'ticketID'		=> $ticketID,
            'ticket_status' => $ticket_status,
            'categoryList'	=> $categoryList,
            'answerList'	=> $answerList,
        ]);
    }
}
