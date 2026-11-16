<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\AccountUtil;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Mail;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Theme;
use App\Security\PasswordSupport;

/** Account settings: language, timezone, email and password, account deletion (modes: show, send, toggleDelete). */
#[\App\Routing\GamePage('account.php', 'accountSettings')]
#[\App\Routing\GamePage('account.php', 'accountSettings', uniPrefix: true)]
class AccountSettingsController extends AbstractAccountController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Settings form of the account (language, theme, timezone, e-mail, password, deletion flag).
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['PUBLIC', 'INGAME', 'ACCOUNT']);
        $ACCOUNT = $this->state->ACCOUNT;

        $this->assign([
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'Skins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getLanguageOptions(),
            ],
            'id'            => $ACCOUNT['id'],
            'delete'        => $ACCOUNT['db_deaktjava'],
            'accountname'   => $ACCOUNT['accountname'],
            'userLang'      => $ACCOUNT['lang'],
            'theme'         => $ACCOUNT['dpath'],
            'timezone'      => $ACCOUNT['timezone'],
            'toplink'       => "account?page=account",
            'topname'       => $LNG->format('Back'),
        ]);

        return $this->render('account/page.accountSettings.default.html.twig');
    }

    /**
     * Saves the account settings (mode=send) in one transaction. Request: password (current), newpassword and newpassword2,
     * email, language, theme, timezone (language/theme must be among the offered ones, otherwise the current is kept).
     * A new password needs the current one and matching repetitions; it revokes all sessions of the account and logs it out.
     * A changed e-mail needs the current password, a valid address that is not used by another account or pending
     * registration; it is stored with `setmail` = now + 7 days (change period) and a notification mail is sent to the old
     * address (email_2). Language, theme and timezone are always written. Ends with a message page.
     */
    public function send()
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['PUBLIC', 'INGAME', 'ACCOUNT']);
        $THEME = $this->state->THEME;
        // passwords are used exactly as typed (no HTML encoding, trimming or "?" for non-ASCII)
        $password           = $this->http()->rawInput('password');
        $newpassword        = $this->http()->rawInput('newpassword');
        $newpassword2       = $this->http()->rawInput('newpassword2');
        $email              = $this->http()->_GP('email', $this->state->ACCOUNT['email']);
        $language           = $this->http()->_GP('language', '');
        $theme              = $this->http()->_GP('theme', $THEME->getThemeName());
        $timezone           = (string) $this->http()->_GP('timezone', '');
        $timezone = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : (string) $this->state->ACCOUNT['timezone'];

        $language = array_key_exists($language, $LNG->getAllowedLangs(false)) ? $language : $LNG->getLanguage();
        $theme = array_key_exists($theme, Theme::getAvailableCssThemes()) ? $theme : $THEME->getThemeName();

        $db = $this->db;
        $LNG->includeData(['PUBLIC']);
        $db->startTransaction();
        $sql = "SELECT * FROM %%ACCOUNTS%% a WHERE a.id = :id FOR UPDATE;";
        $db->select($sql, [
            ':id' => $this->state->ACCOUNT['id'],
        ]);
        $this->assign([
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'Skins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getLanguageOptions(),
            ],
            'id'            => $this->state->ACCOUNT['id'],
            'delete'        => $this->state->ACCOUNT['db_deaktjava'],
            'accountname'   => $this->state->ACCOUNT['accountname'],
            'userLang'      => $this->state->ACCOUNT['lang'],
            'theme'         => $this->state->ACCOUNT['dpath'],
            'timezone'      => $this->state->ACCOUNT['timezone'],
            'toplink'       => "account?page=account",
            'topname'       => $LNG->format('Back'),
        ]);
        $this->changePassword($password, $newpassword, $newpassword2);

        $this->changeEmail($email, $password);

        $sql =  "UPDATE %%ACCOUNTS%% SET
		lang				        = :lang,
		dpath				        = :theme,
        css_dpath                   = :cssTheme,
		timezone				    = :timezone
		WHERE id = :userID;";
        $db->update($sql, [
            ':lang'              => $language,
            ':theme'             => $theme,
            ':cssTheme'          => $theme,
            ':timezone'          => $timezone,
            ':userID'            => $this->state->ACCOUNT['id'],
        ]);
        $this->state->ACCOUNT['dpath'] = $theme;
        $this->state->ACCOUNT['css_dpath'] = $theme;
        $this->assign([
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'Skins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getLanguageOptions(),
            ],
            'id'            => $this->state->ACCOUNT['id'],
            'accountname'   => $this->state->ACCOUNT['accountname'],
            'userLang'      => $this->state->ACCOUNT['lang'],
            'theme'         => $this->state->ACCOUNT['dpath'],
            'timezone'      => $this->state->ACCOUNT['timezone'],
            'toplink'       => "account?page=account",
            'topname'       => $LNG->format('Back'),
        ]);
        $db->commit();

        $this->printMessage($LNG['op_options_changed'], [
            [
                'label' => $LNG['sys_forward'],
                'url'   => 'account?page=accountSettings',
            ],
        ]);
    }

    /**
     * Checks the typed current password (limited per account: a hijacked session cannot guess it); a spent limit ends
     * the request with the "too many attempts" message.
     */
    private function currentPasswordMatches(string $password): bool
    {
        if (!$this->rateLimits->allowAccountSettingsPassword((int) $this->state->ACCOUNT['id'])) {
            $this->printMessage($this->state->LNG['login_error_4'], [[
                'label' => $this->state->LNG['sys_back'],
                'url'   => 'account?page=accountSettings',
            ]]);
        }

        return PasswordSupport::verifyAny($password, $this->state->ACCOUNT['password']);
    }

    /**
     * Sets a new password when the current one is given and correct and both repetitions match: re-hashes it, revokes
     * every session of the account and logs this one out. Otherwise nothing happens.
     */
    private function changePassword(mixed $password, mixed $newpassword, mixed $newpassword2): void
    {
        $db = $this->db;
        if (
            $newpassword !== ''
            && $password !== ''
            && $this->currentPasswordMatches($password)
            && $newpassword === $newpassword2
        ) {
            if (strlen($newpassword) < PasswordSupport::MIN_LENGTH) {
                $this->printMessage($this->state->LNG->format('registerErrorPasswordLength', PasswordSupport::MIN_LENGTH), [[
                    'label' => $this->state->LNG['sys_back'],
                    'url'   => 'account?page=accountSettings',
                ]]);
            }

            $newpass     = $this->accountUtil()->cryptPassword($newpassword);
            $sql = "UPDATE %%ACCOUNTS%% SET password = :newpass WHERE id = :accountID;";
            $db->update($sql, [
                ':newpass'  => $newpass,
                ':accountID'   => $this->state->ACCOUNT['id'],
            ]);
            // open reset links of the account do not survive a password change
            $db->update('UPDATE %%LOSTPASSWORD%% SET hasChanged = 1 WHERE accountID = :accountID AND hasChanged = 0;', [':accountID' => $this->state->ACCOUNT['id']]);
            // the new password ends every session of the account, this one included
            $this->sessions()->revokeAccountSessions((int) $this->state->ACCOUNT['id']);
            $this->state->auth->logout();
        }
    }

    /**
     * Changes the e-mail address when it differs from the current one: needs the current password, a valid address that
     * no other account or pending registration uses (each failure ends with a message page), stores it with
     * `setmail` = now + 7 days and notifies the old address (email_2).
     */
    private function changeEmail(mixed $email, mixed $password): void
    {
        $LNG = $this->state->LNG;
        $db = $this->db;

        if (!empty($email) && $email != $this->state->ACCOUNT['email']) {
            if (!$this->currentPasswordMatches((string) $password)) {
                $this->printMessage($LNG['op_need_pass_mail'], [
                    [
                        'label' => $LNG['sys_back'],
                        'url'   => 'game?page=settings',
                    ],
                ]);
            } elseif (!ValidateAddress($email)) {
                $this->printMessage($LNG['op_not_vaild_mail'], [
                    [
                        'label' => $LNG['sys_back'],
                        'url'   => 'account?page=accountSettings',
                    ],
                ]);
            } else {
                $sql = "SELECT"
                    . " (SELECT COUNT(*) FROM %%ACCOUNTS%% WHERE id != :accountID AND (email ="
                    . " :email OR email_2 = :email)) + (SELECT COUNT(*) FROM %%ACCOUNTS_VALID%% WHERE email = :email) as count";
                $Count = $db->selectSingle($sql, [
                    ':accountID'   => $this->state->ACCOUNT['id'],
                    ':email'    => $email,
                ], 'count');

                if (!empty($Count)) {
                    $this->printMessage($LNG->format('op_change_mail_exist', $email), [
                        [
                            'label' => $LNG['sys_back'],
                            'url'   => 'account?page=accountSettings',
                        ],
                    ]);
                } else {
                    $sql = "UPDATE %%ACCOUNTS%% SET email = :email, setmail = :time WHERE id = :userID;";
                    $db->update($sql, [
                        ':email'    => $email,
                        ':time'     => ($this->state->runtime->timestamp + 604800),
                        ':userID'   => $this->state->ACCOUNT['id'],
                    ]);

                    $config = $this->configs->get();

                    $MailRAW = $LNG->getTemplate('email_mail_changed');
                    $MailContent = str_replace([
                        '{USERNAME}',
                        '{GAMENAME}',
                        '{DISCORD}',
                    ], [
                        $this->state->ACCOUNT['accountname'],
                        $config->game_name,
                        $this->configs->get()->discord_url,
                    ], $MailRAW);

                    $subject = $LNG->formatIn(['mail', 'subject', 'mail_changed'], $config->game_name);
                    $this->mailer()->send($this->state->ACCOUNT['email_2'], $this->state->ACCOUNT['accountname'], $subject, $MailContent);
                }
            }
        }
    }

    /**
     * Toggles the account deletion flag (`db_deaktjava` = timestamp of the request, or 0 to cancel). The flag is acted on
     * later by the maintenance cronjob. Ends with a message page.
     */
    public function toggleDelete()
    {
        $LNG = $this->state->LNG;
        $ACCOUNT = $this->state->ACCOUNT;

        $db = $this->db;
        $db->startTransaction();

        $sql = "SELECT id FROM %%ACCOUNTS%% WHERE id = :accountID FOR UPDATE;";
        $db->selectSingle($sql, [
            ':accountID'   => $ACCOUNT['id'],
        ]);
        $this->assign([
            'toplink'       => "account?page=account",
            'topname'       => $LNG->format('Back'),
        ]);
        if ($ACCOUNT['db_deaktjava'] === 0) {
            $sql = "UPDATE %%ACCOUNTS%% SET db_deaktjava = :timestamp WHERE id = :accountID;";
            $db->update($sql, [
                ':accountID'       => $ACCOUNT['id'],
                ':timestamp'    => $this->state->runtime->timestamp,
            ]);
            $db->commit();

            $text = new \Twig\Markup(
                htmlspecialchars($LNG['op_options_deletion_activated'], ENT_QUOTES, 'UTF-8', false) . '<br>' . htmlspecialchars($LNG['op_options_no_other_settings_changed'], ENT_QUOTES, 'UTF-8', false),
                'UTF-8',
            );
            $this->printMessage($text, [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => 'account?page=accountSettings',
                ],
            ]);
        } else {
            $sql = "UPDATE %%ACCOUNTS%% SET db_deaktjava = 0 WHERE id = :accountID;";
            $db->update($sql, [':accountID'   => $ACCOUNT['id']]);
            $db->commit();

            $text = new \Twig\Markup(
                htmlspecialchars($LNG['op_options_deletion_deactivated'], ENT_QUOTES, 'UTF-8', false) . '<br>' . htmlspecialchars($LNG['op_options_no_other_settings_changed'], ENT_QUOTES, 'UTF-8', false),
                'UTF-8',
            );
            $this->printMessage($text, [
                [
                    'label' => $LNG['sys_forward'],
                    'url'   => 'account?page=accountSettings',
                ],
            ]);
        }
    }
}
