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
use App\Game\Core\DisposableEmailDomainBlocklist;
use App\Routing\GameRoute;

/** Registration: creates an account and its first player (modes: show, send). */
class RegisterController extends AbstractLoginController
{
    /**
     * Registration form (universe status, rules and privacy checkboxes, optional referral name).
     */
    #[GameRoute('index', 'register', 'register')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $referralData = ['id' => 0, 'name' => ''];
        $accountName = "";

        $referralID = $this->input()->int('referralID');
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

        $lang = $this->state->requestCookies['lang'] ?? 'de';

        return $this->render('login/page.register.default.html.twig', [
            'referralData' => $referralData,
            'accountName' => $accountName,
            'timezones' => get_timezone_selector(),
            'rulesUrl' => $this->router->generate('login_rules', ['lang' => $lang]),
            'privacyUrl' => $this->router->generate('login_data_privacy_show', ['lang' => $lang]),
        ]);
    }

    /**
     * Registers a new account (mode=send). Request: username, password, passwordReplay, email, emailReplay, rules, privacy
     * (checkboxes, must be 1), lang, time (timezone), referralID, pow (bot-check solution) and the empty honeypot `website`;
     * without a valid solution the request is refused first. Validation (all errors are collected and shown together): rate
     * limit per IP, valid and free account name, password of at least 6 characters and equal repetition, valid, non-disposable
     * and unused e-mail (also against pending registrations), rules and privacy accepted. Stores a pending row in ACCOUNTS_VALID;
     * sends the verification mail, or, when mail/validation is off, redirects straight to the verification link.
     */
    #[GameRoute('index', 'register', 'register', 'send', ['POST'])]
    public function send(): Response
    {
        $LNG = $this->state->LNG;
        $config = $this->configs->get();

        $formUrl = $this->router->generate('login_register_show');
        if ($failure = $this->botCheckFailure($formUrl, $LNG['registerBack'])) {
            return $failure;
        }

        if (!$this->rateLimits->allowRegistration($this->sessions()->getClientIp())) {
            return $this->messageResponse($LNG['login_error_4'], [[
                'label' => $LNG['registerBack'],
                'url' => $formUrl,
            ]]);
        }

        $userName       = $this->input()->string('username', '', UTF8_SUPPORT);
        $password       = $this->input()->rawString('password');
        $password2      = $this->input()->rawString('passwordReplay');
        $mailAddress    = $this->input()->string('email');
        $mailAddress2   = $this->input()->string('emailReplay');
        $rulesChecked   = $this->input()->int('rules');
        $privacyChecked = $this->input()->int('privacy');
        $language       = $this->input()->string('lang');
        $language       = array_key_exists($language, $LNG->getAllowedLangs(false)) ? $language : $LNG->getLanguage();
        $timezone       = $this->input()->string('time');
        $timezone       = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : (string) $config->timezone;

        $referralID = $this->input()->int('referralID');

        // name and e-mail checks and the insert run under one lock: two parallel requests cannot both pass the checks
        $verifyURL = '';
        $errors = $this->withRegistrationLock(function () use (&$verifyURL, $userName, $password, $password2, $mailAddress, $mailAddress2, $rulesChecked, $privacyChecked, $language, $timezone, $referralID): array {
            $errors = $this->registrationErrors($userName, $password, $password2, $mailAddress, $mailAddress2, $rulesChecked, $privacyChecked);
            if (empty($errors)) {
                $verifyURL = $this->storePendingRegistration($userName, $password, $mailAddress, $language, $this->validReferralId($referralID), $timezone);
            }

            return $errors;
        });

        if (!empty($errors)) {
            return $this->messageResponse(new \Twig\Markup(implode("<br>\r\n", array_map(static fn($e) => htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8', false), $errors)), 'UTF-8'), [[
                'label' => $LNG['registerBack'],
                'url' => '#',
                'action' => 'history-back',
            ]]);
        }

        if ($config->mail_active == 0 || $config->user_valid == 0) {
            return $this->redirectResponse($verifyURL);
        }

        $this->sendVerificationMail($userName, $mailAddress, $verifyURL);

        return $this->messageResponse($LNG['registerSendComplete']);
    }

    /**
     * Validates the registration form and returns all error texts (name empty/invalid/taken, password length and
     * repetition, mail invalid/disposable/empty/repeated/taken incl. pending registrations, rules and privacy accepted).
     * Reads ACCOUNTS and ACCOUNTS_VALID.
     *
     * @return string[] translated error messages in display order
     */
    private function registrationErrors(string $userName, string $password, string $password2, string $mailAddress, string $mailAddress2, mixed $rulesChecked, mixed $privacyChecked): array
    {
        $LNG = $this->state->LNG;

        $errors = [];

        if (empty($userName)) {
            $errors[] = $LNG['registerErrorUsernameEmpty'];
        }

        if (!$this->accountUtil()->isNameValid($userName)) {
            $errors[] = $LNG['registerErrorUsernameChar'];
        }

        if (strlen($password) < \App\Security\PasswordSupport::MIN_LENGTH) {
            $errors[] = $LNG->format('registerErrorPasswordLength', \App\Security\PasswordSupport::MIN_LENGTH);
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

        [$countUsername, $countMail] = $this->countExisting($userName, $mailAddress);

        if ($countUsername != 0) {
            $errors[] = $LNG['registerErrorUsernameExist'];
        }

        if ($countMail != 0) {
            $errors[] = $LNG['registerErrorMailExist'];
        }

        return $errors;
    }

    /**
     * Counts the accounts and pending registrations that already use the name, and those that use the mail address
     * (also as second address).
     *
     * @return array{0: mixed, 1: mixed} name count and mail count
     */
    private function countExisting(string $userName, string $mailAddress): array
    {
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

        return [$countUsername, $countMail];
    }

    /**
     * A referral id only counts when referrals are active and the recruiter exists; otherwise 0.
     */
    private function validReferralId(mixed $referralID): mixed
    {
        $config = $this->configs->get();
        $db = $this->db;

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

        return $referralID;
    }

    /**
     * Stores the pending registration (ACCOUNTS_VALID, hashed password, random validation key) and returns the
     * relative verification link (`verify?i=<id>&k=<key>`).
     */
    private function storePendingRegistration(string $userName, string $password, string $mailAddress, string $language, mixed $referralID, string $timezone): string
    {
        $db = $this->db;

        $validationKey = bin2hex(random_bytes(16));

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
        $verifyURL = 'verify?i=' . $validationID . '&k=' . $validationKey;

        return $verifyURL;
    }

    /**
     * Sends the verification mail with the absolute verification link to the new account's address.
     */
    private function sendVerificationMail(string $userName, string $mailAddress, string $verifyURL): void
    {
        $LNG = $this->state->LNG;
        $config = $this->configs->get();

        $url = $this->canonicalUrl->forPage($this->state->runtime, $verifyURL);
        if ($url === null) {
            return;
        }

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
            $url,
            $this->configs->get()->discord_url,
        ], $MailRAW);

        $subject = $LNG->format('registerMailVertifyTitle', $config->game_name);
        $this->mailer()->send($mailAddress, $userName, $subject, $MailContent);
    }
}
