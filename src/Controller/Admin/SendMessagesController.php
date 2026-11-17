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

use App\Game\Enum\AuthLevel;
use App\Game\Enum\MessageType;
use Doctrine\DBAL\Query\QueryBuilder;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Mail;

/**
 * Sends a mail to the accounts of a universe/language selection (news letter) and in-game messages.
 */
class SendMessagesController extends AbstractAdminController
{
    /** The mass message form; mail modes are hidden when mail is not enabled. */
    #[GameRoute('admin', 'admin/send-messages', 'sendMessages')]
    public function show(): Response
    {
        $db = $this->db;
        $LNG = $this->state->LNG;

        $sendModes = $LNG['ma_modes'];

        if ($this->configs->get()->mail_active == 0) {
            unset($sendModes[1]);
            unset($sendModes[2]);
        }

        $sql = "SELECT `uni`, `value` as 'uni_name' FROM %%CONFIG_UNIVERSE%% WHERE `key` = 'uni_name' ORDER BY `uni` ASC;";
        $universeList = $db->select($sql);

        return $this->render('admin/SendMessagesPage.html.twig', [
            'langSelector'     => array_merge(['' => $LNG['ma_all']], $LNG->getAllowedLangs(false)),
            'universeSelector' => array_merge(['0' => $LNG['ma_all']], array_column($universeList, 'uni_name', 'uni')),
            'modes'            => $sendModes,
        ]);
    }

    /**
     * Validates subject and text, delivers the in-game messages (mode 0 or 2) and/or the mails (mode 1 or 2) to the players of
     * globalmessageuniverse (0 = all) and globalmessagelang (empty = all) and answers with a status text. In-game messages are grouped per
     * universe and signed with the sender's authlevel class; mails go to the distinct addresses of the accounts ({USERNAME} is replaced).
     */
    #[GameRoute('admin', 'admin/send-messages', 'sendMessages', 'send', ['POST'])]
    public function send(): Response
    {
        $LNG = $this->state->LNG;

        $Subject = $this->input()->string('subject', '', true);
        $Message = $this->input()->string('text', '', true);
        $Mode = $this->input()->int('mode');
        $Lang = $this->input()->string('globalmessagelang');
        $universe = $this->input()->int('globalmessageuniverse');

        if (!empty($Message) && !empty($Subject)) {
            if ($Mode == 0 || $Mode == 2) {
                $this->sendGameMessages($Subject, $Message, $universe, $Lang);
            }

            if (($Mode == 1 || $Mode == 2) && !$this->sendMails($Subject, $Message, $universe, $Lang)) {
                return new Response($LNG['ma_no_recipients']);
            }

            return new Response($LNG['ma_message_sended']);
        }

        return new Response($LNG['ma_subject_needed']);
    }

    /**
     * Sends the text as in-game message to all matching players, grouped per universe, signed with the sender's
     * authlevel class (CSS class mod/ops/admin).
     */
    private function sendGameMessages(string $Subject, string $Message, mixed $universe, string $Lang): void
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        switch ($USER['authlevel']) {
            case AuthLevel::Moderator->value:
                $class = 'mod';
                break;
            case AuthLevel::Operator->value:
                $class = 'ops';
                break;
            case AuthLevel::Admin->value:
                $class = 'admin';
                break;
            default:
                $class = '';
                break;
        }

        $From = '<span class="' . $class . '">' . $LNG['user_level_' . $USER['authlevel']] . ' ' . $USER['username'] . '</span>';
        $pmSubject = '<span class="' . $class . '">' . $Subject . '</span>';
        $pmMessage = '<span class="' . $class . '">' . nl2br($Message) . '</span>';
        $qb = $db->qb()
            ->select('`id`', '`username`', '`universe`')
            ->from($db->table('USERS'), 'u')
            ->innerJoin('u', $db->table('USERS_SETTINGS'), 'us', 'us.user_id = u.id');
        $USERS = $db->fetchAll($this->audienceFilter($qb, $universe, $Lang));
        $userList = [];
        foreach ($USERS as $UserData) {
            $userList[$UserData['universe']][] = $UserData['id'];
        }
        foreach ($userList as $universe => $userIDs) {
            $this->playerMessenger()->sendMessage($userIDs, $USER['id'], $From, MessageType::Game->value, $pmSubject, $pmMessage, $this->state->runtime->timestamp, 1, $universe);
        }
    }

    /**
     * Mails the text to the distinct addresses of the accounts of all matching players ({USERNAME} is replaced).
     *
     * @return bool false (nothing sent) when no account matches the filter
     */
    private function sendMails(string $Subject, string $Message, mixed $universe, string $Lang): bool
    {
        $db = $this->db;

        $userList = [];

        $qb = $db->qb()
            ->select('DISTINCT a.`email`', 'a.`accountname`')
            ->from($db->table('USERS'), 'u')
            ->innerJoin('u', $db->table('USERS_SETTINGS'), 'us', 'us.user_id = u.id')
            ->innerJoin('u', $db->table('ACCOUNTS_TO_USERS'), 'au', 'au.user_id = u.id')
            ->innerJoin('au', $db->table('ACCOUNTS'), 'a', 'a.id = au.account_id');
        $USERS = $db->fetchAll($this->audienceFilter($qb, $universe, $Lang));

        foreach ($USERS as $UserData) {
            $userList[$UserData['email']] = [
                'username' => $UserData['accountname'],
                'body'     => nl2br(str_replace('{USERNAME}', $UserData['accountname'], $Message)),
            ];
        }

        if ($userList === []) {
            return false;
        }

        $this->mailer()->multiSend($userList, strip_tags($Subject));

        return true;
    }

    /**
     * Restricts a recipient query (users joined with their settings as u/us) to one universe and/or one language;
     * a universe of 0 and an empty language mean "everyone".
     */
    private function audienceFilter(QueryBuilder $qb, mixed $universe, string $lang): QueryBuilder
    {
        if ($universe > 0) {
            $qb->andWhere('`universe` = :universe')->setParameter('universe', $universe);
        }
        if (!empty($lang)) {
            $qb->andWhere('us.`lang` = :lang')->setParameter('lang', $lang);
        }

        return $qb;
    }
}
