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

    public function newPassword()
    {
        $LNG = & $this->state->LNG;
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

        $newPassword	= uniqid();

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
            DISCORD_URL,
            $newPassword,
        ], $MailRAW);

        if ($config->mail_active && !empty($config->smtp_host)) {
            $subject	= sprintf($LNG['passwordChangedMailTitle'], $config->game_name);
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

    public function send()
    {
        $LNG = & $this->state->LNG;
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

        if (empty($accountID)) {
            $this->printMessage($LNG['passwordErrorUnknown'], [[
                'label'	=> $LNG['passwordBack'],
                'url'	=> './?page=lostPassword',
            ]]);
        }

        $sql = "SELECT COUNT(*) as state FROM %%LOSTPASSWORD%% WHERE accountID = :accountID AND time > :time AND hasChanged = 0;";
        $hasChanged = $db->selectSingle($sql, [
            ':accountID'	=> $accountID,
            ':time'		=> ($this->state->runtime->timestamp - 86400),
        ], 'state');

        if (!empty($hasChanged)) {
            $this->printMessage($LNG['passwordErrorOnePerDay'], [[
                'label'	=> $LNG['passwordBack'],
                'url'	=> './?page=lostPassword',
            ]]);
        }

        $validationKey	= md5(uniqid());

        $sql = "INSERT INTO %%LOSTPASSWORD%% SET accountID = :accountID, `key` = :validationKey, `time` = :timestamp, fromIP = :remoteAddr;";
        $db->insert($sql, [
            ':accountID'	=> $accountID,
            ':timestamp'	=> $this->state->runtime->timestamp,
            ':validationKey' => $validationKey,
            ':remoteAddr'	=> $this->sessions()->getClientIp(),
        ]);

        $MailRAW		= $LNG->getTemplate('email_lost_password_validation');

        $MailContent	= str_replace([
            '{USERNAME}',
            '{GAMENAME}',
            '{VALIDURL}',
        ], [
            $accountname,
            $config->game_name,
            $this->state->runtime->httpPath . './?page=lostPassword&mode=newPassword&u=' . $accountID . '&k=' . $validationKey,
        ], $MailRAW);

        if ($config->mail_active && !empty($config->smtp_host)) {
            $subject	= sprintf($LNG['passwordValidMailTitle'], $config->game_name);
            $this->mailer()->send($mail, $accountname, $subject, $MailContent);

            $this->printMessage($LNG['passwordValidMailSend'], [[
                'label'	=> $LNG['passwordNext'],
                'url'	=> './',
            ]]);
        } else {
            $verifyURL = './?page=lostPassword&mode=newPassword&u=' . $accountID . '&k=' . $validationKey;
            $this->redirectTo($verifyURL);
            // $validurl = HTTP_PATH.'./?page=lostPassword&mode=newPassword&u='.$userID.'&k='.$validationKey;
            // echo '<meta http-equiv="refresh" content="0; url='.$validurl.'"/>';
        }
    }
}
