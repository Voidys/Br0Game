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
use App\Game\Core\MarketManager;
use App\Game\Messages\MessageComposer;
use App\Game\Messages\MessageListing;
use App\Game\Messages\MessageMailbox;

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

    private function mailbox(): MessageMailbox
    {
        return new MessageMailbox($this->db, $this->configs, $this->state);
    }

    private function composer(): MessageComposer
    {
        return new MessageComposer($this->db, $this->configs, $this->state);
    }

    private function sendData($Code, $Message)
    {
        $this->sendJSON([
            'code' => $Code,
            'mess' => $Message,
        ]);
    }

    /**
     * Toggles the favourite flag of a message (XHR, request: favMessID); answers JSON.
     */
    public function favMessage()
    {
        $LNG = $this->state->LNG;

        $this->setWindow('ajax');

        $favMessID = $this->http()->_GP('favMessID', 0);

        if (empty($favMessID)) {
            $this->sendData(0, $LNG['error']);
        }

        $this->sendData($this->mailbox()->toggleFavorite($favMessID), 'OK');
    }

    /**
     * Deletes a message of the player (XHR, request: delMessID); answers JSON.
     */
    public function deleteMessage()
    {
        $LNG = $this->state->LNG;

        $this->setWindow('ajax');

        $delMessID  = $this->http()->_GP('delMessID', 0);

        if (empty($delMessID)) {
            $this->sendData(0, $LNG['error']);
        }

        $this->mailbox()->delete($delMessID);

        $this->sendData($delMessID, $LNG['mg_deleted']);
    }

    /**
     * Bulk action on messages (form post): messcat (category), site (page), messageID[] and action, applied by the mailbox service. Without an action
     * nothing happens; redirects back to the same category page.
     */
    public function action()
    {
        $MessCategory  	= $this->http()->_GP('messcat', MESSAGE_TYPE_ALL);
        $page		 	= $this->http()->_GP('site', 1);
        $messageIDs		= $this->http()->_GP('messageID', []);
        $action	        = $this->http()->_GP('action', "");
        $redirectUrl	= 'game?page=messages&category=' . $MessCategory . '&site=' . $page;

        if ($action == "") {
            $this->redirectTo($redirectUrl);
        }

        $this->mailbox()->applyAction($action, $MessCategory, $messageIDs);
        $this->redirectTo($redirectUrl);
    }

    public function loadUser()
    {
        $searchtext		= $this->http()->_GP('searchtext', '', true);
        $this->sendJSON($this->mailbox()->searchRecipients($searchtext));
    }

    /**
     * Forwards an own message to other players (XHR, request: to[] receivers, id message); validates ownership and receivers, answers JSON.
     */
    public function sendForward()
    {
        $LNG = $this->state->LNG;
        $receiver   = $this->http()->_GP('to', []);
        $messageID  = $this->http()->_GP('id', 0);
        $composer = $this->composer();

        if (empty($receiver) || empty($messageID)) {
            $this->sendJSON($LNG['mg_error']);
        }

        if ($composer->tooManyRecipients($receiver)) {
            $this->sendJSON(sprintf($LNG['mg_too_many_recipients'], MessageComposer::MAX_RECIPIENTS));
        }

        if (!$composer->ownsMessage($messageID)) {
            $this->sendJSON($LNG['mg_error']);
        }

        if (!$this->rateLimits->allowPrivateMessage((int) $this->state->USER['id'])) {
            $this->sendJSON($LNG['mg_rate_limited']);
        }

        $receiverList = $composer->deliverableReceivers($receiver);

        if (empty($receiverList)) {
            $this->sendJSON($LNG['mg_error']);
        }

        $composer->forward($receiverList, $messageID);

        $this->sendJSON($LNG['mg_message_send']);
    }

    /**
     * Sends a new message (XHR, request: to[] receivers, subject, text, groupid). Needs receivers and text; receivers who block messages are dropped (all
     * blocked: error). Answers JSON with the result text.
     */
    public function send()
    {
        $LNG = $this->state->LNG;
        $receiver   = $this->http()->_GP('to', []);
        $subject    = $this->http()->_GP('subject', $LNG['mg_no_subject'], true);
        $text       = $this->http()->_GP('text', '', true);
        $groupKey   = $this->http()->_GP('groupid', "", true);

        // the subject field allows 40 typed characters: cut the decoded text, so no entity is split and the column (255) holds it
        $subject    = htmlspecialchars(mb_substr(html_entity_decode((string) $subject, ENT_QUOTES | ENT_HTML5, 'UTF-8'), 0, 40), ENT_QUOTES, 'UTF-8');
        $text		= makebr($text);

        if (empty($receiver) || empty($text)) {
            $this->sendJSON($LNG['mg_error']);
        }

        $composer = $this->composer();
        if ($composer->tooManyRecipients($receiver)) {
            $this->sendJSON(sprintf($LNG['mg_too_many_recipients'], MessageComposer::MAX_RECIPIENTS));
        }
        if ($composer->textTooLong((string) $text)) {
            $this->sendJSON(sprintf($LNG['mg_text_too_long'], MessageComposer::MAX_TEXT_LENGTH));
        }
        if (!$this->rateLimits->allowPrivateMessage((int) $this->state->USER['id'])) {
            $this->sendJSON($LNG['mg_rate_limited']);
        }

        $receiverList = $composer->deliverableReceivers($receiver);

        if (empty($receiverList)) {
            $this->sendJSON($LNG['mg_blocked']);
        }

        $composer->send($receiverList, $receiver, $subject, $text, $groupKey);

        $this->sendJSON($LNG['mg_message_send']);
    }

    /**
     * Compose form prefilled with the text of message `id` (to forward it).
     */
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

    /**
     * Compose popup. Request: receivers[] (player ids), subject, message, groupid. Looks up the receivers' home coordinates and names and whether they block PMs.
     */
    public function write(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $this->setWindow('popup');

        $db = $this->db;

        $receivers      = $this->http()->_GP('receivers', [], true);
        $Subject        = $this->http()->_GP('subject', $LNG['mg_no_subject'], true);
        $Message        = $this->http()->_GP('message', "", true);
        $messageGroup   = $this->http()->_GP('groupid', "", true);

        if (!is_array($receivers)) {
            $receivers = [$receivers];
        }

        $receiverIds = array_slice(array_values(array_unique(array_filter(array_map('intval', $receivers)))), 0, \App\Game\Messages\MessageComposer::MAX_RECIPIENTS);

        $receiverList = [];
        if ($receiverIds !== []) {
            $sql = "SELECT p.`galaxy`, p.`system`, p.`planet`, u.`id`, u.`username`, u.`id_planet`, us.`settings_blockPM`
                FROM %%USERS%% as u
                INNER JOIN %%USERS_SETTINGS%% us ON us.`user_id` = u.`id`
                INNER JOIN %%PLANETS%% as p ON p.`id` = u.`id_planet`
                WHERE u.`id` IN (" . implode(',', $receiverIds) . ") AND u.`universe` = :universe;";

            foreach ($db->select($sql, [':universe' => $this->universe()->current()]) as $receiverRecord) {
                $receiverList[$receiverRecord['id']] = $receiverRecord;
            }
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
     * Message center. Request: category (-1 = category overview), site (page), fav (1 = favourites only), searchtext. The list comes from MessageListing;
     * the grouping (cookie msgGrouping), the player's simulator display settings and the market reference ratios are passed to the template.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $MessCategory = $this->http()->_GP('category', -1);
        $page = $this->http()->_GP('site', 1);
        $fav = $this->http()->_GP('fav', 0);
        $searchtext = $this->http()->_GP('searchtext', '', true);

        $grp = isset($this->state->requestCookies['msgGrouping']) ? (int) $this->state->requestCookies['msgGrouping'] : 0;

        $fleetIntoDebris = $this->configs->get()->fleet_debris_percentage;
        $defIntoDebris = $this->configs->get()->def_debris_percentage;
        $uniType = $this->configs->get()->uni_type;
        $maxGalaxy = $this->configs->get()->max_galaxy;

        $stbSettings2 = $this->playerUtil()->player_stb_settings($USER);

        $combustion_tech = $USER["combustion_tech"];
        $impulse_motor_tech = $USER["impulse_motor_tech"];

        $list = (new MessageListing($this->db, $this->configs, $this->state))->listPage($MessCategory, $page, $fav, $searchtext, $grp);

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $refrates = $pMarket->getReferenceRatios();
        $this->addScript('message.js');
        $this->assign([
            'MessID'		                => $MessCategory,
            'MessageCount'	                => $list['MessageCount'],
            'MessageList'	                => $list['MessageList'],
            'CategoryList'	                => $list['CategoryList'],
            'page'			                => $list['page'],
            'maxPage'		                => $list['maxPage'],
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
