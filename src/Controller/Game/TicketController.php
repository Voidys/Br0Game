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
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\Discord;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\SupportTickets;
use App\Game\Core\Universe;

/**
 * Support tickets of the player: list, create, view and answer.
 */
#[\App\Routing\GamePage('game', 'ticket')]
#[\App\Controller\RequireModule('MODULE_SUPPORT')]
class TicketController extends AbstractGameController
{
    /** open (not closed) tickets one player may have before new ones are refused */
    public const MAX_OPEN_TICKETS = 5;

    private $ticketObj;

    public function initialize(): void
    {
        parent::initialize();
        $this->ticketObj = new SupportTickets($this->db, $this->configs, $this->state);
    }

    /**
     * The player's tickets with answer counts.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $sql = 'SELECT t.*, COUNT(a.`ticketID`) as answer
            FROM %%TICKETS%% t
            INNER JOIN %%TICKETS_ANSWER%% a USING (`ticketID`)
            WHERE t.`ownerID` = :userID 
            GROUP BY a.`ticketID` 
            ORDER BY t.`ticketID` DESC;';

        $ticketResult = $db->select($sql, [
            ':userID' => $USER['id'],
        ]);

        $ticketList = [];

        foreach ($ticketResult as $ticketRow) {
            $ticketRow['time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $ticketRow['time'], $USER['timezone']);

            $ticketList[$ticketRow['ticketID']] = $ticketRow;
        }

        $this->assign([
            'ticketList' => $ticketList,
        ]);

        return $this->render('game/page.ticket.default.html.twig');
    }

    /**
     * Form for a new ticket.
     */
    public function create(): Response
    {
        $categoryList = $this->ticketObj->getCategoryList();
        // Remove debug category for users to choose from
        unset($categoryList[9]);

        $this->assign([
            'categoryList' => $categoryList,
        ]);

        return $this->render('game/page.ticket.create.html.twig');
    }

    /**
     * Creates a ticket or answers one. Request: message (required; without it the request redirects back to the form or to the ticket), id (0 = new
     * ticket), category and subject (new tickets; a missing subject shows an error message). For an existing ticket (id) it must belong to the player, not
     * be a debug ticket (category 9) and not be closed (`ti_error_closed`); the answer then gets the subject `RE: <ticket subject>`. The Discord ticket hook (when
     * configured) is notified about new tickets and replies; the text is stored through SupportTickets::createAnswer and the request redirects to the ticket.
     */
    public function send()
    {
        $USER = $this->state->USER;
        $ticketID = $this->http()->_GP('id', 0);
        $categoryID = $this->http()->_GP('category', 0);
        $message = $this->http()->_GP('message', '', true);
        $subject = $this->http()->_GP('subject', '', true);

        if (empty($message)) {
            if (empty($ticketID)) {
                $this->redirectTo('game?page=ticket&mode=create');
            } else {
                $this->redirectTo('game?page=ticket&mode=view&id=' . $ticketID);
            }
        }

        if (empty($ticketID)) {
            $ticketID = $this->createNewTicket($categoryID, $subject, $message);
        } else {
            $subject = $this->replySubject($ticketID, $message);
        }

        $this->ticketObj->createAnswer($ticketID, $USER['id'], $USER['username'], $subject, $message, 0);
        $this->redirectTo('game?page=ticket&mode=view&id=' . $ticketID);
    }

    /**
     * Validates and creates a new ticket (subject required, open-ticket and rate limits) and notifies the Discord hook.
     * Violations end the request with a message. Returns the new ticket id.
     */
    private function createNewTicket(mixed $categoryID, string $subject, string $message): int
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $config = $this->configs->get();

        if (empty($subject)) {
            $this->printMessage($LNG['ti_error_no_subject'], [[
                'label' => $LNG['sys_back'],
                'url' => '#',
                'action' => 'history-back',
            ]]);
        }

        $open = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%TICKETS%% WHERE `ownerID` = :ownerID AND `status` != 2 AND `categoryID` != 9;', [
            ':ownerID' => $USER['id'],
        ], 'c');
        if ($open >= self::MAX_OPEN_TICKETS) {
            $this->printMessage($LNG->format('ti_error_too_many_open', self::MAX_OPEN_TICKETS));
        }
        if (!$this->rateLimits->allowTicket((int) $USER['id'])) {
            $this->printMessage($LNG['ti_error_rate_limited']);
        }

        $ticketID = $this->ticketObj->createTicket($USER['id'], $categoryID, $subject);

        if (isset($config->discord_tickets_hook) && !empty($config->discord_tickets_hook)) {
            $this->discord()->sendMessage($config->discord_tickets_hook, 'New ingame ticket created', [
                'Universe' => $this->universe()->current(),
                'Ticket-ID' => '[' . $ticketID . '](' . $this->state->runtime->protocol . $this->state->runtime->httpHost . '/admin?page=support&mode=view&id=' . $ticketID . ')',
                'User' => $USER['username'] . ' (' . $USER['id'] . ')',
                'Subject' => $subject,
                'Message' => $message,
            ]);
        }

        return $ticketID;
    }

    /**
     * Checks that the ticket belongs to the player, is no debug ticket and not closed (violations end the request with a
     * message), notifies the Discord hook about the reply and returns the answer subject `RE: <ticket subject>`.
     */
    private function replySubject(mixed $ticketID, string $message): string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $config = $this->configs->get();

        $db = $this->db;

        $sql = 'SELECT `status`, `subject` 
            FROM %%TICKETS%% 
            WHERE `ticketID` = :ticketID AND `ownerID` = :ownerID AND `categoryID` != 9;';
        $ticket = $db->selectSingle($sql, [
            ':ticketID' => $ticketID,
            ':ownerID' => $USER['id'],
        ]);

        if (empty($ticket)) {
            $this->printMessage($LNG->format('ti_not_exist', $ticketID), [[
                'label' => $LNG['sys_back'],
                'url' => 'game?page=ticket',
            ]]);
        }
        $subject = 'RE: ' . $ticket['subject'];

        if ($ticket['status'] == 2) {
            $this->printMessage($LNG['ti_error_closed']);
        } elseif (isset($config->discord_tickets_hook) && !empty($config->discord_tickets_hook)) {
            $this->discord()->sendMessage($config->discord_tickets_hook, 'New ticket reply', [
                'Universe' => $this->universe()->current(),
                'Ticket-ID' => '[' . $ticketID . '](' . $this->state->runtime->protocol . $this->state->runtime->httpHost . '/admin?page=support&mode=view&id=' . $ticketID . ')',
                'User' => $USER['username'] . ' (' . $USER['id'] . ')',
                'Subject' => $subject,
                'Message' => $message,
            ]);
        }

        return $subject;
    }

    /**
     * One ticket with its answers (request: id); unknown or foreign tickets end in a message.
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
            WHERE a.`ticketID` = :ticketID AND t.`ownerID` = :ownerID AND t.`categoryID` != 9
            ORDER BY a.`answerID`;';
        $answerResult = $db->select($sql, [
            ':ticketID' => $ticketID,
            ':ownerID' => $USER['id'],
        ]);

        $answerList = [];

        if (empty($answerResult)) {
            $this->printMessage($LNG->format('ti_not_exist', $ticketID), [[
                'label' => $LNG['sys_back'],
                'url' => 'game?page=ticket',
            ]]);
        }

        $ticket_status = 0;

        foreach ($answerResult as $answerRow) {
            $answerRow['time'] = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $answerRow['time'], $USER['timezone']);
            $answerList[$answerRow['answerID']] = $answerRow;
            if (empty($ticket_status)) {
                $ticket_status = $answerRow['status'];
            }
        }

        $categoryList = $this->ticketObj->getCategoryList();

        $this->assign([
            'ticketID' => $ticketID,
            'categoryList' => $categoryList,
            'answerList' => $answerList,
            'status' => $ticket_status,
        ]);

        return $this->render('game/page.ticket.view.html.twig');
    }
}
