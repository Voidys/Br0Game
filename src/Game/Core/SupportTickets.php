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

namespace App\Game\Core;

/**
 * Support ticket storage (tickets, tickets_answer, tickets_category); also used by the error reporter to open debug tickets in category 9.
 */
class SupportTickets
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Creates a ticket in the current universe and returns its id.
     * @param int $ownerID user id (0 = system).
     * @param int $categoryID ticket category.
     */
    public function createTicket($ownerID, $categoryID, $subject)
    {
        $db = $this->db;
        $sql 	= 'INSERT INTO %%TICKETS%% SET
		ownerID		= :ownerId,
		universe	= :universe,
		categoryID	= :categoryId,
		subject		= :subject,
		time		= :time;';

        $db->insert($sql, [
            ':ownerId'		=> $ownerID,
            ':universe'		=> $this->universe()->current(),
            ':categoryId'	=> $categoryID,
            ':subject'		=> $subject,
            ':time'			=> $this->state->runtime->timestamp
        ]);

        $id = $db->lastInsertId();

        return $id;
    }

    /**
     * Adds an answer to a ticket, sets the ticket status and returns the answer id.
     * @param int $status new ticket status: 0 for new tickets and player replies, 1 and 2 are set by the admin SupportController (2 = closed).
     */
    public function createAnswer($ticketID, $ownerID, $ownerName, $subject, $message, $status)
    {
        $db = $this->db;
        $sql = 'INSERT INTO %%TICKETS_ANSWER%% SET
		ticketID	= :ticketId,
		ownerID		= :ownerId,
		ownerName	= :ownerName,
		subject		= :subject,
		message		= :message,
		time		= :time;';

        $db->insert($sql, [
            ':ticketId'		=> $ticketID,
            ':ownerId'		=> $ownerID,
            ':ownerName'	=> $ownerName,
            ':subject'		=> $subject,
            ':message'		=> $message,
            ':time'			=> $this->state->runtime->timestamp
        ]);

        $answerId = $db->lastInsertId();

        $sql	= 'UPDATE %%TICKETS%% SET status = :status WHERE ticketID = :ticketId;';

        $db->update($sql, [
            ':status'	=> $status,
            ':ticketId'	=> $ticketID
        ]);

        return $answerId;
    }

    /**
     * Ticket categories.
     * @return array<int, string> category id => name.
     */
    public function getCategoryList()
    {
        $sql	= 'SELECT * FROM %%TICKETS_CATEGORY%%;';

        $categoryResult		= $this->db->select($sql);
        $categoryList		= [];

        foreach ($categoryResult as $categoryRow) {
            $categoryList[$categoryRow['categoryID']]	= $categoryRow['name'];
        }

        return $categoryList;
    }
}
