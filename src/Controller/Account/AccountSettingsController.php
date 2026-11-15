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

/** Account settings: language, timezone, email and password, account deletion (modes: show, send, toggleDelete). */
#[\App\Routing\GamePage('account.php', 'accountSettings')]
#[\App\Routing\GamePage('account.php', 'accountSettings', uniPrefix: true)]
class AccountSettingsController extends AbstractAccountController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $LNG->includeData(['INGAME', 'ACCOUNT']);
        $ACCOUNT = & $this->state->ACCOUNT;

        $this->assign([
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'Skins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getAllowedLangs(false),
            ],
            'id'            => $ACCOUNT['id'],
            'delete'        => $ACCOUNT['db_deaktjava'],
            'accountname'   => $ACCOUNT['accountname'],
            'userLang'      => $ACCOUNT['lang'],
            'theme'         => $ACCOUNT['dpath'],
            'timezone'      => $ACCOUNT['timezone'],
            'toplink'       => "account?page=account",
            'topname'       => sprintf($LNG['Back']),
        ]);

        return $this->render('account/page.accountSettings.default.html.twig');
    }

    public function send()
    {
        $ACCOUNT = & $this->state->ACCOUNT;
        $LNG = & $this->state->LNG;
        $LNG->includeData(['INGAME', 'ACCOUNT']);
        $THEME = & $this->state->THEME;
        $password           = $this->http()->_GP('password', '');
        $newpassword        = $this->http()->_GP('newpassword', '');
        $newpassword2       = $this->http()->_GP('newpassword2', '');
        $email              = $this->http()->_GP('email', $ACCOUNT['email']);
        $language           = $this->http()->_GP('language', '');
        $theme              = $this->http()->_GP('theme', $THEME->getThemeName());
        $timezone           = $this->http()->_GP('timezone', '');

        $language = array_key_exists($language, $LNG->getAllowedLangs(false)) ? $language : $LNG->getLanguage();
        $theme = array_key_exists($theme, Theme::getAvailableCssThemes()) ? $theme : $THEME->getThemeName();

        $db = $this->db;
        $db->startTransaction();
        $sql = "SELECT * FROM %%ACCOUNTS%% a WHERE a.id = :id FOR UPDATE;";
        $db->select($sql, [
            ':id' => $ACCOUNT['id'],
        ]);
        $this->assign([
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'Skins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getAllowedLangs(false),
            ],
            'id'            => $ACCOUNT['id'],
            'delete'        => $ACCOUNT['db_deaktjava'],
            'accountname'   => $ACCOUNT['accountname'],
            'userLang'      => $ACCOUNT['lang'],
            'theme'         => $ACCOUNT['dpath'],
            'timezone'      => $ACCOUNT['timezone'],
            'toplink'       => "account?page=account",
            'topname'       => sprintf($LNG['Back']),
        ]);
        if (
            !empty($newpassword)
            && !empty($password)
            && password_verify($password, $ACCOUNT['password'])
            && $newpassword == $newpassword2
        ) {
            $newpass     = $this->accountUtil()->cryptPassword($newpassword);
            $sql = "UPDATE %%ACCOUNTS%% SET password = :newpass WHERE id = :accountID;";
            $db->update($sql, [
                ':newpass'  => $newpass,
                ':accountID'   => $ACCOUNT['id'],
            ]);
            // the new password ends every session of the account, this one included
            $this->sessions()->revokeAccountSessions((int) $ACCOUNT['id']);
            $this->state->auth->logout();
        }

        if (!empty($email) && $email != $ACCOUNT['email']) {
            if (!password_verify($password, $ACCOUNT['password'])) {
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
                    ':accountID'   => $ACCOUNT['id'],
                    ':email'    => $email,
                ], 'count');

                if (!empty($Count)) {
                    $this->printMessage(sprintf($LNG['op_change_mail_exist'], $email), [
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
                        ':userID'   => $ACCOUNT['id'],
                    ]);

                    $config = $this->configs->get();

                    $MailRAW = $LNG->getTemplate('email_mail_changed');
                    $MailContent = str_replace([
                        '{USERNAME}',
                        '{GAMENAME}',
                        '{DISCORD}',
                    ], [
                        $ACCOUNT['accountname'],
                        $config->game_name,
                        DISCORD_URL,
                    ], $MailRAW);

                    $subject = sprintf($LNG['mail']['subject']['mail_changed'], $config->game_name);
                    $this->mailer()->send($ACCOUNT['email_2'], $ACCOUNT['accountname'], $subject, $MailContent);
                }
            }
        }

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
            ':userID'            => $ACCOUNT['id'],
        ]);
        $ACCOUNT['dpath'] = $theme;
        $ACCOUNT['css_dpath'] = $theme;
        $this->assign([
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'Skins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getAllowedLangs(false),
            ],
            'id'            => $ACCOUNT['id'],
            'accountname'   => $ACCOUNT['accountname'],
            'userLang'      => $ACCOUNT['lang'],
            'theme'         => $ACCOUNT['dpath'],
            'timezone'      => $ACCOUNT['timezone'],
            'toplink'       => "account?page=account",
            'topname'       => sprintf($LNG['Back']),
        ]);
        $db->commit();

        $this->printMessage($LNG['op_options_changed'], [
            [
                'label' => $LNG['sys_forward'],
                'url'   => 'account?page=accountSettings',
            ],
        ]);
    }

    public function toggleDelete()
    {
        $LNG = & $this->state->LNG;
        $ACCOUNT = & $this->state->ACCOUNT;

        $db = $this->db;
        $db->startTransaction();

        $sql = "SELECT id FROM %%ACCOUNTS%% WHERE id = :accountID FOR UPDATE;";
        $db->selectSingle($sql, [
            ':accountID'   => $ACCOUNT['id'],
        ]);
        $this->assign([
            'toplink'       => "account?page=account",
            'topname'       => sprintf($LNG['Back']),
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
