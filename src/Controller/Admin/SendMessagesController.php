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
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Mail;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;

/**
 * Sends a mail to the accounts of a universe/language selection (news letter) and in-game messages.
 */
#[\App\Routing\GamePage('admin.php', 'sendMessages')]
#[\App\Routing\GamePage('admin.php', 'sendMessages', uniPrefix: true)]
class SendMessagesController extends AbstractAdminController
{
    /**
     * Mass messages. action=send with subject, text, mode (0 in-game message, 1 mail, 2 both), globalmessageuniverse (0 = all) and
     * globalmessagelang (empty = all languages). In-game messages go to the players, grouped per universe, signed with the sender's
     * authlevel class; mails go to the distinct addresses of the accounts of the selected players ({USERNAME} is replaced). The form
     * hides mail when mail is not enabled.
     */
    public function show(): Response
    {
        $db = $this->db;
        $LNG = $this->state->LNG;

        $ACTION = $this->http()->_GP('action', '');
        if ($ACTION == 'send') {
            $this->sendBroadcast();
        }

        $sendModes = $LNG['ma_modes'];

        if ($this->configs->get()->mail_active == 0) {
            unset($sendModes[1]);
            unset($sendModes[2]);
        }

        $sql = "SELECT `uni`, `value` as 'uni_name' FROM %%CONFIG_UNIVERSE%% WHERE `key` = 'uni_name' ORDER BY `uni` ASC;";
        $universeList = $db->select($sql);

        $this->assign([
            'langSelector'     => array_merge(['' => $LNG['ma_all']], $LNG->getAllowedLangs(false)),
            'universeSelector' => array_merge(['0' => $LNG['ma_all']], array_column($universeList, 'uni_name', 'uni')),
            'modes'            => $sendModes,
        ]);
        return $this->render('admin/SendMessagesPage.html.twig');
    }

    /**
     * Action send: validates subject and text, delivers the in-game messages (mode 0 or 2) and/or the mails (mode 1 or 2)
     * and ends the request with a status text.
     */
    private function sendBroadcast(): void
    {
        $LNG = $this->state->LNG;

        $Subject = $this->http()->_GP('subject', '', true);
        $Message = $this->http()->_GP('text', '', true);
        $Mode = $this->http()->_GP('mode', 0);
        $Lang = $this->http()->_GP('globalmessagelang', '');
        $universe = $this->http()->_GP('globalmessageuniverse', 0);

        if (!empty($Message) && !empty($Subject)) {
            if ($Mode == 0 || $Mode == 2) {
                $this->sendGameMessages($Subject, $Message, $universe, $Lang);
            }

            if (($Mode == 1 || $Mode == 2) && !$this->sendMails($Subject, $Message, $universe, $Lang)) {
                throw new \App\Game\Core\EndOfRequest($LNG['ma_no_recipients']);
            }
            throw new \App\Game\Core\EndOfRequest($LNG['ma_message_sended']);
        } else {
            throw new \App\Game\Core\EndOfRequest($LNG['ma_subject_needed']);
        }
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
            case AUTH_MOD:
                $class = 'mod';
                break;
            case AUTH_OPS:
                $class = 'ops';
                break;
            case AUTH_ADM:
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
            $this->playerUtil()->sendMessage($userIDs, $USER['id'], $From, MESSAGE_TYPE_GAME, $pmSubject, $pmMessage, $this->state->runtime->timestamp, 1, $universe);
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
