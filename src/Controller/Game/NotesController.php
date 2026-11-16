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
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Personal notes of the player (list, edit popup, delete).
 */
#[\App\Routing\GamePage('game.php', 'notes')]
#[\App\Routing\GamePage('game.php', 'notes', uniPrefix: true)]
class NotesController extends AbstractGameController
{
    public static $requireModule = 'MODULE_NOTICE';

    public function initialize(): void
    {
        parent::initialize();
        $this->setWindow('popup');
    }

    /**
     * List of the player's notes, highest priority first, newest first.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;

        $sql = "SELECT * FROM %%NOTES%% WHERE owner = :userID ORDER BY priority DESC, time DESC;";
        $notesResult = $db->select($sql, [
            ':userID'   => $USER['id'],
        ]);

        $notesList		= [];

        foreach ($notesResult as $notesRow) {
            $notesList[$notesRow['id']]	= [
                'time'		=> $this->viewHelpers()->formatDate($LNG['php_tdformat'], $notesRow['time'], $USER['timezone']),
                'title'		=> $notesRow['title'],
                'size'		=> strlen($notesRow['text']),
                'priority'	=> $notesRow['priority'],
            ];
        }

        $this->assign([
            'notesList'	=> $notesList,
        ]);

        return $this->render('game/page.notes.default.html.twig');
    }

    /**
     * Edit popup of one note (request: id; 0 = new note).
     */
    public function detail(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $noteID		= $this->http()->_GP('id', 0);

        if (!empty($noteID)) {
            $db = $this->db;

            $sql = "SELECT * FROM %%NOTES%% WHERE id = :noteID AND owner = :userID;";
            $noteDetail = $db->selectSingle($sql, [
                ':userID'   => $USER['id'],
                ':noteID'   => $noteID,
            ]);
        } else {
            $noteDetail	= [
                'id'		=> 0,
                'priority'	=> 1,
                'text'		=> '',
                'title'		=> '',
            ];
        }

        $this->assign([
            'PriorityList'	=> [2 => $LNG['nt_important'], 1 => $LNG['nt_normal'], 0 => $LNG['nt_unimportant']],
            'noteDetail'	=> $noteDetail,
        ]);

        return $this->render('game/page.notes.detail.html.twig');
    }

    /**
     * Saves a note (request: id, priority, title, text); id 0 inserts, otherwise the player's own note is updated. The encoded title is cut to
     * 32 characters without leaving a broken entity. Redirects to the list.
     */
    public function insert()
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $priority 	= $this->http()->_GP('priority', 1);
        $title 		= $this->http()->_GP('title', '', true);
        $text 		= $this->http()->_GP('text', '', true);
        $id			= $this->http()->_GP('id', 0);
        $title 		= !empty($title) ? $title : $LNG['nt_no_title'];
        $text 		= !empty($text) ? $text : $LNG['nt_no_text'];
        // the title is stored encoded (e.g. & -> &amp;); cut it so that it fits into varchar(32)
        if (mb_strlen($title) > 32) {
            // do not leave a truncated entity (e.g. "&am") at the end
            $title = preg_replace('/&#?[a-zA-Z0-9]*$/', '', mb_substr($title, 0, 32));
        }

        $db = $this->db;

        if ($id == 0) {
            $sql = "INSERT INTO %%NOTES%% SET owner = :userID, time = :time, priority = :priority, title = :title, text = :text, universe = :universe;";
            $db->insert($sql, [
                ':userID'   => $USER['id'],
                ':time'     => $this->state->runtime->timestamp,
                ':priority' => $priority,
                ':title'    => $title,
                ':text'     => $text,
                ':universe' => $this->universe()->current(),
            ]);
        } else {
            $sql	= "UPDATE %%NOTES%% SET time = :time, priority = :priority, title = :title, text = :text WHERE id = :noteID AND owner = :userID;";
            $db->update($sql, [
                ':noteID'   => $id,
                ':userID'   => $USER['id'],
                ':time'     => $this->state->runtime->timestamp,
                ':priority' => $priority,
                ':title'    => $title,
                ':text'     => $text,
            ]);
        }

        $this->redirectTo('game?page=notes');
    }

    /**
     * Deletes the selected notes (request: delmes[<id>]); only the player's own notes are affected. Redirects to the list.
     */
    public function delete()
    {
        $USER = $this->state->USER;

        $deleteIds	= $this->http()->_GP('delmes', []);
        $deleteIds	= array_keys($deleteIds);
        $deleteIds	= array_map('intval', array_filter($deleteIds, 'is_numeric'));

        if (!empty($deleteIds)) {
            // one bound placeholder per id: request values never reach the SQL text
            $params = [':userID' => $USER['id']];
            $placeholders = [];
            foreach (array_values($deleteIds) as $i => $noteId) {
                $placeholders[] = ':note' . $i;
                $params[':note' . $i] = $noteId;
            }
            $sql = 'DELETE FROM %%NOTES%% WHERE id IN (' . implode(', ', $placeholders) . ') AND owner = :userID;';
            $this->db->delete($sql, $params);
        }
        $this->redirectTo('game?page=notes');
    }
}
