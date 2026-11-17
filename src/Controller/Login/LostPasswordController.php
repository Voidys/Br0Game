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
use App\Routing\GameRoute;

/** Lost password: requests a reset mail and sets a new password from the mail link (modes: show, send, newPassword). */
class LostPasswordController extends AbstractLoginController
{
    /** seconds a reset link stays valid */
    private const KEY_LIFETIME = 1800;
    /** seconds an unused link blocks a new request for the same account (a lost mail can be requested again after that) */
    private const REQUEST_COOLDOWN = 600;

    private ?\App\Security\DeferredWork $deferred = null;

    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setDeferredWork(\App\Security\DeferredWork $deferred): void
    {
        $this->deferred = $deferred;
    }

    /** message page for an unknown, used or expired key */
    private function invalidKey(): Response
    {
        return $this->messageResponse($this->state->LNG['passwordValidInValid'], [[
            'label'	=> $this->state->LNG['passwordBack'],
            'url'	=> './',
        ]]);
    }

    /**
     * Lost-password form; redirects to the start page when mail is not enabled in the universe config.
     */
    #[GameRoute('index', 'lost-password', 'lostPassword')]
    public function show(): Response
    {
        if (!$this->configs->get()->mail_active) {
            return $this->redirectResponse('./');
        }

        return $this->render('login/page.lostPassword.default.html.twig', [
            'universeSelect'	=> $this->getUniverseSelector(),
        ]);
    }

    /**
     * Mail link target (mode=newPassword). Request: u (account id), k (key). A GET only shows a confirmation form
     * (mail scanners and link previews open links, so a GET must not use the key up); the POST of that form sets the new
     * password. The key must belong to the account, be unused (`hasChanged` = 0) and not older than 30 minutes; it is claimed
     * with one conditional UPDATE, so it works once even for parallel requests. Writes the new password hash to ACCOUNTS,
     * revokes the other sessions of the account and mails the result (on systems without working mail the new password
     * is shown on the page).
     */
    #[GameRoute('index', 'lost-password/reset', 'lostPassword', 'newPassword', ['GET', 'POST'])]
    public function newPassword(): Response
    {
        $LNG = $this->state->LNG;
        $accountID		= $this->input()->int('u');
        $validationKey	= $this->input()->string('k');

        $db = $this->db;

        $oldest = $this->state->runtime->timestamp - self::KEY_LIFETIME;
        if (!$this->state->request->isMethod('POST')) {
            $sql = "SELECT COUNT(*) as state FROM %%LOSTPASSWORD%% WHERE accountID = :accountID AND `key` = :validationKey AND `time` > :time AND hasChanged = 0;";
            $isValid = $db->selectSingle($sql, [
                ':accountID'		=> $accountID,
                ':validationKey'	=> $validationKey,
                ':time'				=> $oldest,
            ], 'state');
            if (empty($isValid)) {
                return $this->invalidKey();
            }

            return $this->render('login/page.lostPassword.confirm.html.twig', ['accountID' => $accountID, 'validationKey' => $validationKey]);
        }

        // single use: only the request that flips hasChanged gets to set the password
        $sql = "UPDATE %%LOSTPASSWORD%% SET hasChanged = 1 WHERE accountID = :accountID AND `key` = :validationKey AND `time` > :time AND hasChanged = 0;";
        $db->update($sql, [
            ':accountID'		=> $accountID,
            ':validationKey'	=> $validationKey,
            ':time'				=> $oldest,
        ]);
        if ($db->rowCount() !== 1) {
            return $this->invalidKey();
        }

        $newPassword	= bin2hex(random_bytes(6));
        // the new password ends every other session of the account
        $this->sessions()->revokeAccountSessions((int) $accountID);

        $sql = "UPDATE %%ACCOUNTS%% SET password = :newPassword WHERE id = :accountID;";
        $db->update($sql, [
            ':accountID'		=> $accountID,
            ':newPassword'	=> $this->accountUtil()->cryptPassword($newPassword),
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

            return $this->messageResponse($LNG['passwordChangedMailSend'], [[
                'label'	=> $LNG['passwordNext'],
                'url'	=> './',
            ]]);
        }

        return $this->messageResponse(new \Twig\Markup(nl2br(htmlspecialchars((string) $MailContent, ENT_QUOTES, 'UTF-8', false)), 'UTF-8'), [[
            'label'	=> $LNG['passwordNext'],
            'url'	=> './',
        ]]);
    }

    /**
     * Requests a reset mail (mode=send). Request: username (account name), mail (the account's second address `email_2`).
     * Needs working mail, a valid bot-check solution (fields `pow`, honeypot `website`), passes the lost-password rate limit per IP, and allows one open request per account per 10 minutes.
     * Stores a key in LOSTPASSWORD and mails a link (mode=newPassword). Unknown accounts and repeated requests get the same
     * answer as a successful one, so account names cannot be enumerated.
     */
    #[GameRoute('index', 'lost-password', 'lostPassword', 'send', ['POST'])]
    public function send(): Response
    {
        $LNG = $this->state->LNG;
        $accountname	= $this->input()->string('username', '', UTF8_SUPPORT);
        $mail		= $this->input()->string('mail', '', true);

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
            return $this->redirectResponse('./');
        }

        $formUrl = $this->router->generate('login_lost_password_show');
        if ($failure = $this->botCheckFailure($formUrl, $LNG['passwordBack'])) {
            return $failure;
        }

        if (!$this->rateLimits->allowLostPassword($this->sessions()->getClientIp())) {
            return $this->messageResponse($LNG['login_error_4'], [[
                'label'	=> $LNG['passwordBack'],
                'url'	=> $formUrl,
            ]]);
        }

        if (!empty($errorMessages)) {
            $message	= new \Twig\Markup(implode("<br>\r\n", array_map(static fn($e) => htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8', false), $errorMessages)), 'UTF-8');

            return $this->messageResponse($message, [[
                'label'	=> $LNG['passwordBack'],
                'url'	=> $formUrl,
            ]]);
        }

        $db = $this->db;

        $sql = "SELECT id FROM %%ACCOUNTS%% WHERE accountname = :accountname AND email_2 = :mail;";
        $accountID = $db->selectSingle($sql, [
            ':accountname'	=> $accountname,
            ':mail'		=> $mail,
        ], 'id');

        // an unused link younger than REQUEST_COOLDOWN blocks a new one (spam), an older one does not (lost mail)
        $sql = "SELECT COUNT(*) as state FROM %%LOSTPASSWORD%% WHERE accountID = :accountID AND time > :time AND hasChanged = 0;";
        $recent = $db->selectSingle($sql, [
            ':accountID'	=> (int) $accountID,
            ':time'		=> ($this->state->runtime->timestamp - self::REQUEST_COOLDOWN),
        ], 'state');

        // unknown accounts, repeated requests and real ones get the same answer in the same time: key and mail are
        // handled after the response was sent
        if (!empty($accountID) && empty($recent)) {
            $this->deferred?->add(function () use ($accountname, $mail, $accountID): void {
                $validationKey = $this->storeResetRequest($accountID);
                $this->sendResetMail($accountname, $mail, $accountID, $validationKey);
            });
        }

        return $this->messageResponse($LNG['passwordValidMailSend'], [[
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

        // an older unused link of the account stops working with the new one
        $db->update("UPDATE %%LOSTPASSWORD%% SET hasChanged = 1 WHERE accountID = :accountID AND hasChanged = 0;", [':accountID' => $accountID]);

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
     * Mails the reset link (`lost-password/reset?u=<account>&k=<key>`, canonical absolute URL) to the
     * account's second address.
     */
    private function sendResetMail(string $accountname, string $mail, mixed $accountID, string $validationKey): void
    {
        $LNG = $this->state->LNG;
        $config = $this->configs->get();

        $url = $this->canonicalUrl->forPage($this->state->runtime, 'lost-password/reset?u=' . $accountID . '&k=' . $validationKey);
        if ($url === null) {
            return;
        }

        $MailRAW		= $LNG->getTemplate('email_lost_password_validation');

        $MailContent	= str_replace([
            '{USERNAME}',
            '{GAMENAME}',
            '{VALIDURL}',
        ], [
            $accountname,
            $config->game_name,
            $url,
        ], $MailRAW);

        $subject	= $LNG->format('passwordValidMailTitle', $config->game_name);
        $this->mailer()->send($mail, $accountname, $subject, $MailContent);
    }
}
