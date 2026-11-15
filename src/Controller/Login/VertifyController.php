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

namespace App\Controller\Login;

use Exception;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\AccountUtil;
use App\Game\Core\Mail;

/** Account verification link from the registration mail (modes: show, json). */
#[\App\Routing\GamePage('index.php', 'vertify')]
#[\App\Routing\GamePage('index.php', 'vertify', uniPrefix: true)]
class VertifyController extends AbstractLoginController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    private function _activeUser()
    {
        $LNG = & $this->state->LNG;

        $validationID	= $this->http()->_GP('i', 0);
        $validationKey	= $this->http()->_GP('k', '');

        $db = $this->db;

        $sql = "SELECT * FROM %%ACCOUNTS_VALID%%
		WHERE validationID	= :validationID
		AND validationKey	= :validationKey
		AND date > :oldest;";

        // verification links are valid for 3 days (the daily cronjob removes older rows)
        $userData = $db->selectSingle($sql, [
            ':validationKey'	=> $validationKey,
            ':validationID'		=> $validationID,
            ':oldest'		=> $this->state->runtime->timestamp - 3 * TIME_24_HOURS,
        ]);

        if (empty($userData)) {
            $this->printMessage($LNG['vertifyNoUserFound']);
        }

        $config	= $this->configs->get();

        $sql = "DELETE FROM %%ACCOUNTS_VALID%% WHERE validationID = :validationID;";
        $db->delete($sql, [
            ':validationID'	=> $validationID,
        ]);
        $accountID = $this->accountUtil()->createAccount($userData['accountname'], $userData['password'], $userData['email'], $userData['language'], 0, $userData['ip'], $userData['timezone']);

        if ($config->mail_active == 1) {
            $MailSubject	= sprintf($LNG['registerMailCompleteTitle'], $config->game_name);
            $MailRAW		= $LNG->getTemplate('email_reg_done');
            $MailContent	= str_replace([
                '{USERNAME}',
                '{GAMENAME}',
                '{DISCORD}',
            ], [
                $userData['accountname'],
                $config->game_name,
                DISCORD_URL,
            ], $MailRAW);

            try {
                $this->mailer()->send($userData['email'], $userData['accountname'], $MailSubject, $MailContent);
            } catch (Exception $e) {
                // This mail is wayne.
            }
        }

        if (!empty($userData['referralID'])) {
            $sql = "UPDATE %%USERS%% SET
			`ref_id`	= :referralId,
			`ref_bonus`	= 1
			WHERE
			`id`		= :userID;";

            $db->update($sql, [
                ':referralId'	=> $userData['referralID'],
                ':userID'		=> $userID,
            ]);
        }

        return [
            'accountID'	=> $accountID,
            'accountname'	=> $userData['accountname'],
        ];
    }

    public function show()
    {
        $userData	= $this->_activeUser();
        $db = $this->db;
        $sql = "SELECT * FROM %%ACCOUNTS%% WHERE `id` = :accountID;";
        $ACCOUNT = $db->selectSingle($sql, [
            ':accountID'	=> $userData['accountID'],
        ]);
        $this->state->ACCOUNT = $ACCOUNT;
        $ACCOUNT = & $this->state->ACCOUNT;
        // verified: log the new account in (the game session follows on the account page)
        $this->state->auth->login((int) $userData['accountID']);

        $this->http()->redirectTo('account');
    }

    public function json()
    {
        $LNG = & $this->state->LNG;
        $userData	= $this->_activeUser();
        $this->sendJSON(sprintf($LNG['vertifyAdminMessage'], $userData['accountname']));
    }
}
