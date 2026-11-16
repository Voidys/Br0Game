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

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\AccountUtil;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Mail;
use App\Game\Core\Session;
use App\Game\Core\Singleton;

/** Lost password: requests a reset mail and sets a new password from the mail link (modes: show, send, newPassword). */
#[\App\Routing\GamePage('index.php', 'lostPassword')]
#[\App\Routing\GamePage('index.php', 'lostPassword', uniPrefix: true)]
class LostPasswordController extends AbstractLoginController
{
    public static $requireModule = 0;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Lost-password form; redirects to the start page when mail is not enabled in the universe config.
     */
    public function show(): Response
    {
        if (!$this->configs->get()->mail_active) {
            $this->redirectTo('./');
        }

        $universeSelect	= $this->getUniverseSelector();

        $this->assign([
            'universeSelect'	=> $universeSelect,
        ]);

        return $this->render('login/page.lostPassword.default.html.twig');
    }

    /**
     * Sets a new password from the mail link (mode=newPassword). Request: u (account id), k (key). The key must belong to the
     * account, be unused (`hasChanged` = 0) and not older than 30 minutes. Writes the new password hash to ACCOUNTS, marks the
     * key used, revokes the other sessions of the account and mails the result (on systems without working mail the new
     * password is shown on the page).
     */
    public function newPassword()
    {
        $LNG = $this->state->LNG;
        $accountID		= $this->http()->_GP('u', 0);
        $validationKey	= $this->http()->_GP('k', '');

        $db = $this->db;

        $sql = "SELECT COUNT(*) as state FROM %%LOSTPASSWORD%% WHERE accountID = :accountID AND `key` = :validationKey AND `time` > :time AND hasChanged = 0;";
        $isValid = $db->selectSingle($sql, [
            ':accountID'		=> $accountID,
            ':validationKey'	=> $validationKey,
            ':time'				=> ($this->state->runtime->timestamp - 1800),
        ], 'state');

        if (empty($isValid)) {
            $this->printMessage($LNG['passwordValidInValid'], [[
                'label'	=> $LNG['passwordBack'],
                'url'	=> './',
            ]]);
        }

        $newPassword	= bin2hex(random_bytes(6));
        // the new password ends every other session of the account
        $this->sessions()->revokeAccountSessions((int) $accountID);

        $sql = "UPDATE %%ACCOUNTS%% SET password = :newPassword WHERE id = :accountID;";
        $db->update($sql, [
            ':accountID'		=> $accountID,
            ':newPassword'	=> $this->accountUtil()->cryptPassword($newPassword),
        ]);

        $sql = "UPDATE %%LOSTPASSWORD%% SET hasChanged = 1 WHERE accountID = :accountID AND `key` = :validationKey;";
        $db->update($sql, [
            ':accountID'			=> $accountID,
            ':validationKey'	=> $validationKey,
        ]);

        $sql = "SELECT accountname, email_2 as mail FROM %%ACCOUNTS%% WHERE id = :accountID;";
        $accountData = $db->selectSingle($sql, [
            ':accountID'	=> $accountID,
        ]);

        $config			= $this->configs->get();

        $MailRAW		= $LNG->getTemplate('email_lost_password_changed');
        $MailContent	= str_replace([
            '{USERNAME}',
            '{GAMENAME}',
            '{DISCORD}',
            '{PASSWORD}',
        ], [
            $accountData['accountname'],
            $config->game_name,
            $this->configs->get()->discord_url,
            $newPassword,
        ], $MailRAW);

        if ($config->mail_active && !empty($config->smtp_host)) {
            $subject	= $LNG->format('passwordChangedMailTitle', $config->game_name);
            $this->mailer()->send($accountData['mail'], $accountData['accountname'], $subject, $MailContent);

            $this->printMessage($LNG['passwordChangedMailSend'], [[
                'label'	=> $LNG['passwordNext'],
                'url'	=> './',
            ]]);
        } else {
            $this->printMessage(new \Twig\Markup(nl2br(htmlspecialchars((string) $MailContent, ENT_QUOTES, 'UTF-8', false)), 'UTF-8'), [[
                'label'	=> $LNG['passwordNext'],
                'url'	=> './',
            ]]);
        }
    }

    /**
     * Requests a reset mail (mode=send). Request: username (account name), mail (the account's second address `email_2`).
     * Needs working mail, passes the lost-password rate limit per IP, and allows one open request per account per 24 hours.
     * Stores a key in LOSTPASSWORD and mails a link (mode=newPassword). Unknown accounts and repeated requests get the same
     * answer as a successful one, so account names cannot be enumerated.
     */
    public function send()
    {
        $LNG = $this->state->LNG;
        $accountname	= $this->http()->_GP('username', '', UTF8_SUPPORT);
        $mail		= $this->http()->_GP('mail', '', true);

        $errorMessages	= [];

        if (empty($accountname)) {
            $errorMessages[]	= $LNG['passwordErrorUsernameEmpty'];
        }

        if (empty($mail)) {
            $errorMessages[]	= $LNG['passwordErrorMailEmpty'];
        }

        $config	= $this->configs->get();

        // without working mail the reset link would have to be shown on screen to whoever asks
        if (!$config->mail_active || empty($config->smtp_host)) {
            $this->redirectTo('./');
        }

        if (!$this->rateLimits->allowLostPassword($this->sessions()->getClientIp())) {
            $this->printMessage($LNG['login_error_4'], [[
                'label'	=> $LNG['passwordBack'],
                'url'	=> './?page=lostPassword',
            ]]);
        }

        if (!empty($errorMessages)) {
            $message	= new \Twig\Markup(implode("<br>\r\n", array_map(static fn($e) => htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8', false), $errorMessages)), 'UTF-8');
            $this->printMessage($message, [[
                'label'	=> $LNG['passwordBack'],
                'url'	=> './?page=lostPassword',
            ]]);
        }

        $db = $this->db;

        $sql = "SELECT id FROM %%ACCOUNTS%% WHERE accountname = :accountname AND email_2 = :mail;";
        $accountID = $db->selectSingle($sql, [
            ':accountname'	=> $accountname,
            ':mail'		=> $mail,
        ], 'id');

        // unknown accounts get the same answer as known ones (no account enumeration)
        if (empty($accountID)) {
            $this->printMessage($LNG['passwordValidMailSend'], [[
                'label'	=> $LNG['passwordNext'],
                'url'	=> './',
            ]]);
        }

        $sql = "SELECT COUNT(*) as state FROM %%LOSTPASSWORD%% WHERE accountID = :accountID AND time > :time AND hasChanged = 0;";
        $hasChanged = $db->selectSingle($sql, [
            ':accountID'	=> $accountID,
            ':time'		=> ($this->state->runtime->timestamp - 86400),
        ], 'state');

        if (!empty($hasChanged)) {
            $this->printMessage($LNG['passwordValidMailSend'], [[
                'label'	=> $LNG['passwordNext'],
                'url'	=> './',
            ]]);
        }
$validationKey = $this->storeResetRequest($accountID);

        $this->sendResetMail($accountname, $mail, $accountID, $validationKey);

        $this->printMessage($LNG['passwordValidMailSend'], [[
            'label'	=> $LNG['passwordNext'],
            'url'	=> './',
        ]]);
    }

    /**
     * Stores a reset request (LOSTPASSWORD: account, random key, time, client IP).
     *
     * @return string the key that goes into the mailed link
     */
    private function storeResetRequest(mixed $accountID): string
    {
        $db = $this->db;

        $validationKey	= bin2hex(random_bytes(16));

        $sql = "INSERT INTO %%LOSTPASSWORD%% SET accountID = :accountID, `key` = :validationKey, `time` = :timestamp, fromIP = :remoteAddr;";
        $db->insert($sql, [
            ':accountID'	=> $accountID,
            ':timestamp'	=> $this->state->runtime->timestamp,
            ':validationKey' => $validationKey,
            ':remoteAddr'	=> $this->sessions()->getClientIp(),
        ]);

        return $validationKey;
    }

    /**
     * Mails the reset link (`?page=lostPassword&mode=newPassword&u=<account>&k=<key>`, canonical absolute URL) to the
     * account's second address.
     */
    private function sendResetMail(string $accountname, string $mail, mixed $accountID, string $validationKey): void
    {
        $LNG = $this->state->LNG;
        $config = $this->configs->get();

        $MailRAW		= $LNG->getTemplate('email_lost_password_validation');

        $MailContent	= str_replace([
            '{USERNAME}',
            '{GAMENAME}',
            '{VALIDURL}',
        ], [
            $accountname,
            $config->game_name,
            $this->canonicalUrl->forPage($this->state->runtime, './?page=lostPassword&mode=newPassword&u=' . $accountID . '&k=' . $validationKey),
        ], $MailRAW);

        $subject	= $LNG->format('passwordValidMailTitle', $config->game_name);
        $this->mailer()->send($mail, $accountname, $subject, $MailContent);
    }
}
