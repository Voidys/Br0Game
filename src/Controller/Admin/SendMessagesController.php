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
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $db = $this->db;

        $ACTION = $this->http()->_GP('action', '');
        if ($ACTION == 'send') {
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

            $Subject = $this->http()->_GP('subject', '', true);
            $Message = $this->http()->_GP('text', '', true);
            $Mode = $this->http()->_GP('mode', 0);
            $Lang = $this->http()->_GP('globalmessagelang', '');
            $universe = $this->http()->_GP('globalmessageuniverse', 0);

            if (!empty($Message) && !empty($Subject)) {
                if ($Mode == 0 || $Mode == 2) {
                    $From = '<span class="' . $class . '">' . $LNG['user_level_' . $USER['authlevel']] . ' ' . $USER['username'] . '</span>';
                    $pmSubject = '<span class="' . $class . '">' . $Subject . '</span>';
                    $pmMessage = '<span class="' . $class . '">' . nl2br($Message) . '</span>';
                    $sql = "SELECT `id`, `username`, `universe` FROM %%USERS%% u INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id";
                    $params = [];
                    if ($universe > 0 && !empty($Lang)) {
                        $sql .= " WHERE `universe` = :universe AND `us.lang` = :lang";
                        $params = [':universe' => $universe, ':lang' => $Lang];
                    } elseif ($universe > 0) {
                        $sql .= " WHERE `universe` = :universe";
                        $params = [':universe' => $universe];
                    } elseif (!empty($Lang)) {
                        $sql .= " WHERE `us.lang` = :lang";
                        $params = [':lang' => $Lang];
                    }
                    $USERS = $db->select($sql, $params);
                    $userList = [];
                    foreach ($USERS as $UserData) {
                        $userList[$UserData['universe']][] = $UserData['id'];
                    }
                    foreach ($userList as $universe => $userIDs) {
                        $this->playerUtil()->sendMessage($userIDs, $USER['id'], $From, MESSAGE_TYPE_GAME, $pmSubject, $pmMessage, $this->state->runtime->timestamp, 1, $universe);
                    }
                }

                if ($Mode == 1 || $Mode == 2) {
                    $userList = [];

                    $sql = "SELECT DISTINCT a.`email`, a.`accountname` 
                        FROM %%USERS%% u 
                        INNER JOIN %%USERS_SETTINGS%% us ON us.user_id = u.id
                        INNER JOIN %%ACCOUNTS_TO_USERS%% au ON au.user_id = u.id
                        INNER JOIN %%ACCOUNTS%% a ON a.id = au.account_id";
                    $params = [];
                    if ($universe > 0 && !empty($Lang)) {
                        $sql .= " WHERE `universe` = :universe AND `us.lang` = :lang";
                        $params = [':universe' => $universe, ':lang' => $Lang];
                    } elseif ($universe > 0) {
                        $sql .= " WHERE `universe` = :universe";
                        $params = [':universe' => $universe];
                    } elseif (!empty($Lang)) {
                        $sql .= " WHERE `us.lang` = :lang";
                        $params = [':lang' => $Lang];
                    }
                    $USERS = $db->select($sql, $params);

                    foreach ($USERS as $UserData) {
                        $userList[$UserData['email']] = [
                            'username' => $UserData['accountname'],
                            'body'     => nl2br(str_replace('{USERNAME}', $UserData['accountname'], $Message)),
                        ];
                    }

                    $this->mailer()->multiSend($userList, strip_tags($Subject));
                }
                throw new \App\Game\Core\EndOfRequest($LNG['ma_message_sended']);
            } else {
                throw new \App\Game\Core\EndOfRequest($LNG['ma_subject_needed']);
            }
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
}
