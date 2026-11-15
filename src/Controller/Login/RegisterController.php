<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
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
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Universe;
use App\Game\Core\Singleton;
use App\Game\Core\AccountUtil;
use App\Game\Core\DisposableEmailDomainBlocklist;
use App\Game\Core\Session;
use App\Game\Core\Mail;

/** Registration: creates an account and its first player (modes: show, send). */
#[\App\Routing\GamePage('index.php', 'register')]
#[\App\Routing\GamePage('index.php', 'register', uniPrefix: true)]
class RegisterController extends AbstractLoginController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $referralData = ['id' => 0, 'name' => ''];
        $accountName = "";

        $config = $this->configs->get();
        if ($config->ref_active == 1 && !empty($referralID)) {
            $db = $this->db;

            $sql = "SELECT username FROM %%USERS%% WHERE id = :referralID AND universe = :universe;";
            $referralAccountName = $db->selectSingle($sql, [
                ':referralID' => $referralID,
                ':universe' => $this->universe()->current(),
            ], 'username');

            if (!empty($referralAccountName)) {
                $referralData = ['id' => $referralID, 'name' => $referralAccountName];
            }
        }

        $this->assign([
            'referralData' => $referralData,
            'accountName' => $accountName,
            'timezones' => get_timezone_selector(),
            'rulesUrl' => './?page=rules&lang=' . ($this->state->requestCookies['lang'] ?? 'de'),
            'privacyUrl' => './?page=dataPrivacy&lang=' . ($this->state->requestCookies['lang'] ?? 'de'),
        ]);

        return $this->render('login/page.register.default.html.twig');
    }

    public function send()
    {
        $LNG = & $this->state->LNG;
        $config = $this->configs->get();

        $userName       = $this->http()->_GP('username', '', UTF8_SUPPORT);
        $password       = $this->http()->_GP('password', '', true);
        $password2      = $this->http()->_GP('passwordReplay', '', true);
        $mailAddress    = $this->http()->_GP('email', '');
        $mailAddress2   = $this->http()->_GP('emailReplay', '');
        $rulesChecked   = $this->http()->_GP('rules', 0);
        $privacyChecked = $this->http()->_GP('privacy', 0);
        $language       = $this->http()->_GP('lang', '');
        $timezone       = $this->http()->_GP('time', '');

        $referralID = $this->http()->_GP('referralID', 0);

        $errors = [];

        if (empty($userName)) {
            $errors[] = $LNG['registerErrorUsernameEmpty'];
        }

        if (!$this->accountUtil()->isNameValid($userName)) {
            $errors[] = $LNG['registerErrorUsernameChar'];
        }

        if (strlen($password) < 6) {
            $errors[] = sprintf($LNG['registerErrorPasswordLength'], 6);
        }

        if ($password != $password2) {
            $errors[] = $LNG['registerErrorPasswordSame'];
        }

        if (!$this->accountUtil()->isMailValid($mailAddress) || DisposableEmailDomainBlocklist::isBlocked($mailAddress)) {
            $errors[] = $LNG['registerErrorMailInvalid'];
        }

        if (empty($mailAddress)) {
            $errors[] = $LNG['registerErrorMailEmpty'];
        }

        if ($mailAddress != $mailAddress2) {
            $errors[] = $LNG['registerErrorMailSame'];
        }

        if ($rulesChecked != 1) {
            $errors[] = $LNG['registerErrorRules'];
        }

        if ($privacyChecked != 1) {
            $errors[] = $LNG['registerErrorDataPrivacy'];
        }

        $db = $this->db;

        $sql = "SELECT (
				SELECT COUNT(*)
				FROM %%ACCOUNTS%%
				WHERE accountname = :userName
			) + (
				SELECT COUNT(*)
				FROM %%ACCOUNTS_VALID%%
				WHERE accountname = :userName
			) as count;";

        $countUsername = $db->selectSingle($sql, [
            ':userName' => $userName,
        ], 'count');

        $sql = "SELECT (
			SELECT COUNT(*)
			FROM %%ACCOUNTS%%
			WHERE (
				email = :mailAddress
				OR email_2 = :mailAddress
			)
		) + (
			SELECT COUNT(*)
			FROM %%ACCOUNTS_VALID%%
			WHERE email = :mailAddress
		) as count;";

        $countMail = $db->selectSingle($sql, [
            ':mailAddress' => $mailAddress,
        ], 'count');

        if ($countUsername != 0) {
            $errors[] = $LNG['registerErrorUsernameExist'];
        }

        if ($countMail != 0) {
            $errors[] = $LNG['registerErrorMailExist'];
        }

        if (!empty($errors)) {
            $this->printMessage(new \Twig\Markup(implode("<br>\r\n", array_map(static fn($e) => htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8', false), $errors)), 'UTF-8'), [[
                'label' => $LNG['registerBack'],
                'url' => 'javascript:window.history.back()',
            ]]);
        }

        if ($config->ref_active == 1 && !empty($referralID)) {
            $sql = "SELECT COUNT(*) as state FROM %%USERS%% WHERE id = :referralID;";
            $Count = $db->selectSingle($sql, [
                ':referralID' => $referralID,
            ], 'state');

            if ($Count == 0) {
                $referralID = 0;
            }
        } else {
            $referralID = 0;
        }

        $validationKey = md5(uniqid('2m'));

        $sql = "INSERT INTO %%ACCOUNTS_VALID%% SET
				`accountname` = :userName,
				`validationKey` = :validationKey,
				`password` = :password,
				`email` = :mailAddress,
				`date` = :timestamp,
				`ip` = :remoteAddr,
				`language` = :language,
				`referralID` = :referralID,
                `timezone` = :timezone;";


        $db->insert($sql, [
            ':userName' => $userName,
            ':validationKey' => $validationKey,
            ':password' => $this->accountUtil()->cryptPassword($password),
            ':mailAddress' => $mailAddress,
            ':timestamp' => $this->state->runtime->timestamp,
            ':remoteAddr' => $this->sessions()->getClientIp(),
            ':language' => $language,
            ':referralID' => $referralID,
            ':timezone' => $timezone,
        ]);

        $sql = "SELECT validationID AS id FROM %%ACCOUNTS_VALID%% WHERE validationKey = :validationKey;";
        $validationID = $db->selectSingle($sql, [
            ':validationKey' => $validationKey,
        ], 'id');
        $verifyURL = './?page=vertify&i=' . $validationID . '&k=' . $validationKey;

        if ($config->mail_active == 0 || $config->user_valid == 0) {
            $this->redirectTo($verifyURL);
        } else {
            $MailRAW = $LNG->getTemplate('email_vaild_reg');
            $MailContent = str_replace([
                '{USERNAME}',
                '{EMAIL}',
                '{GAMENAME}',
                '{VERTIFYURL}',
                '{DISCORD}',
            ], [
                $userName,
                $mailAddress,
                $config->game_name,
                $this->state->runtime->httpPath . $verifyURL,
                DISCORD_URL,
            ], $MailRAW);

            $subject = sprintf($LNG['registerMailVertifyTitle'], $config->game_name);
            $this->mailer()->send($mailAddress, $userName, $subject, $MailContent);

            $this->printMessage($LNG['registerSendComplete']);
        }
    }
}
