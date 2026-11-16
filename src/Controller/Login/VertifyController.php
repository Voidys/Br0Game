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

    /**
     * Completes the registration from the mail link. Request: i (validation id), k (key); the row in ACCOUNTS_VALID must match
     * and be younger than 3 days, otherwise a message is shown. Removes the pending row, creates the account through
     * AccountUtil::createAccount, mails a welcome message when mail is enabled (failures are ignored) and credits the referrer if
     * a referral id was stored (kept in ACCOUNTS.referralID until the first player is created, see Account\RegisterController).
     */
    private function _activeUser()
    {
        $LNG = $this->state->LNG;

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
            $MailSubject	= $LNG->format('registerMailCompleteTitle', $config->game_name);
            $MailRAW		= $LNG->getTemplate('email_reg_done');
            $MailContent	= str_replace([
                '{USERNAME}',
                '{GAMENAME}',
                '{DISCORD}',
            ], [
                $userData['accountname'],
                $config->game_name,
                $this->configs->get()->discord_url,
            ], $MailRAW);

            try {
                $this->mailer()->send($userData['email'], $userData['accountname'], $MailSubject, $MailContent);
            } catch (Exception $e) {
                // This mail is wayne.
            }
        }

        if (!empty($userData['referralID'])) {
            // no player exists yet: the account carries the referral to its first player (Account\RegisterController)
            $db->update('UPDATE %%ACCOUNTS%% SET `referralID` = :referralId WHERE `id` = :accountID;', [
                ':referralId' => (int) $userData['referralID'],
                ':accountID'  => $accountID,
            ]);
        }

        return [
            'accountID'	=> $accountID,
            'accountname'	=> $userData['accountname'],
        ];
    }

    /**
     * Verification link target: activates the account (see _activeUser) and logs it in, then redirects to /account.
     */
    public function show()
    {
        $userData	= $this->_activeUser();
        $db = $this->db;
        $sql = "SELECT * FROM %%ACCOUNTS%% WHERE `id` = :accountID;";
        $this->state->ACCOUNT = $db->selectSingle($sql, [
            ':accountID'	=> $userData['accountID'],
        ]);
        $this->state->ACCOUNT = $this->state->ACCOUNT;
        // verified: log the new account in (the game session follows on the account page)
        $this->state->auth->login((int) $userData['accountID']);

        $this->http()->redirectTo('account');
    }

    /**
     * Activates the account like show() but does not log in; answers the message vertifyAdminMessage as JSON.
     */
    public function json()
    {
        $LNG = $this->state->LNG;
        $userData	= $this->_activeUser();
        $this->sendJSON($LNG->format('vertifyAdminMessage', $userData['accountname']));
    }
}
