<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Game\Core\Theme;
use App\Game\Core\AccountUtil;

/** Creates a player for the account in another universe (modes: show, send). */
#[\App\Routing\GamePage('account.php', 'register')]
#[\App\Routing\GamePage('account.php', 'register', uniPrefix: true)]
class RegisterController extends AbstractAccountController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $uniId = $this->http()->_GP('uni', '0');
        $LNG = & $this->state->LNG;
        $ACCOUNT = & $this->state->ACCOUNT;
        $LNG->includeData(['INGAME', 'ACCOUNT']);
        $accountName = "";
        $status = "";
        $config = $this->configs->get($uniId);
        if ($config->uni_status == STATUS_CLOSED && $ACCOUNT['authlevel'] < 3) {
            $status = sprintf($LNG['uni_status_regclosed_gameclosed']);
            (new ErrorController())->withGameServicesOf($this)->showError($LNG['uni_status_regclosed_gameclosed']);
        } elseif ($config->uni_status == STATUS_REG_ONLY && $ACCOUNT['authlevel'] < 3) {
            $status = sprintf($LNG['uni_status_regopen_gameclosed']);
        } elseif ($config->uni_status == STATUS_LOGIN_ONLY && $ACCOUNT['authlevel'] < 3) {
            $status = sprintf($LNG['uni_status_regclosed_gameopen']);
            (new ErrorController())->withGameServicesOf($this)->showError($LNG['uni_status_regclosed_gameopen']);
        } elseif ($config->uni_status == STATUS_OPEN && $ACCOUNT['authlevel'] < 3) {
            $status = sprintf($LNG['uni_status_regopen_gameopen']);
        } else {
            $status = sprintf($LNG['uni_status_regopen_gameopen']);
        }

        $db = $this->db;
        $sql = "SELECT `universe` FROM %%USERS%% u
            INNER JOIN %%ACCOUNTS_TO_USERS%% au ON u.id = au.user_id 
            WHERE `account_id` = :accountId;";
        $user_unis = $db->select($sql, [':accountId' => $ACCOUNT['id']]);
        $defaultUnis["default"] = "default";
        foreach ($user_unis as $user_uni) {
            $config = $this->configs->get($user_uni['universe']);
            $defaultUnis[$user_uni['universe']] = $config->uni_name;
        }

        $config = $this->configs->get();
        $this->assign([
            'Selectors'         => [
                'timezones' => get_timezone_selector(),
                'ImageSkins' => Theme::getAvailableImageThemes(),
                'CssSkins' => Theme::getAvailableCssThemes(),
                'lang' => $LNG->getAllowedLangs(false),
            ],
            'accountName' => $accountName,
            'universe' => $config->uni_name,
            'uniId' => $uniId,
            'status' => $status,
            'registerPasswordDesc' => sprintf($LNG['registerPasswordDesc'], 6),
            'toplink'       => "account?page=account",
            'topname'       => sprintf($LNG['Back']),
            'userLang' => $ACCOUNT['lang'],
            'timezone' => $ACCOUNT['timezone'],
            'imageTheme' => Theme::resolveDefaultTheme($config->default_theme ?? null),
            'cssTheme' => Theme::resolveDefaultTheme($config->default_theme ?? null),
            'defaultUnis' => $defaultUnis,
            'default' => $defaultUnis['default'],
        ]);

        return $this->render('account/page.register.default.html.twig');
    }

    public function send()
    {
        $LNG = & $this->state->LNG;
        $ACCOUNT = & $this->state->ACCOUNT;
        $universe = $this->http()->_GP('uni', 0);
        $config = $this->configs->get($universe);
        $LNG->includeData(['INGAME', 'ACCOUNT']);
        $this->assign([
            'accountName' => $ACCOUNT['accountname'],
            'universe' => $config->uni_name,
            'toplink'       => "account?page=account",
            'topname'       => sprintf($LNG['Back']),
        ]);
        if ($config->uni_status == STATUS_CLOSED || $config->uni_status == STATUS_LOGIN_ONLY || $universe == 0) {
            $this->printMessage($LNG['registerErrorUniClosed'], [[
                'label' => $LNG['registerBack'],
                'url' => '#',
                'action' => 'history-back',
            ]]);
        }

        $userName = $this->http()->_GP('username', '', UTF8_SUPPORT);
        $language = $this->http()->_GP('language', 'en');
        $imageTheme = $this->http()->_GP('imageTheme', '');
        $cssTheme = $this->http()->_GP('cssTheme', '');
        $timezone = $this->http()->_GP('timezone', '');
        $colors = $this->http()->_GP('colors', 'default');
        $mission = $this->http()->_GP('missionPrios', 'default');
        $stb = $this->http()->_GP('stbSettings', 'default');

        $errors = [];

        if (empty($userName)) {
            $errors[] = $LNG['registerErrorUsernameEmpty'];
        }

        if (!$this->accountUtil()->isNameValid($userName)) {
            $errors[] = $LNG['registerErrorUsernameChar'];
        }



        $db = $this->db;
        $colorSettingsUni = 'default';
        $missionSettingsUni = 'default';
        $stbSettingsUni = 'default';
        // the dropdowns are keyed by universe id; PlayerUtil::createPlayer only copies from the account's own players
        if ($colors != 'default' && (int) $colors > 0) {
            $colorSettingsUni = (string) (int) $colors;
        }
        if ($mission != 'default' && (int) $mission > 0) {
            $missionSettingsUni = (string) (int) $mission;
        }
        if ($stb != 'default' && (int) $stb > 0) {
            $stbSettingsUni = (string) (int) $stb;
        }
        $sql = "SELECT COUNT(*) as count
				FROM %%USERS%%
				WHERE universe = :universe
				AND username = :userName;";

        $countUsername = $db->selectSingle($sql, [
            ':universe' => $universe,
            ':userName' => $userName,
        ], 'count');

        if ($countUsername != 0) {
            $errors[] = $LNG['registerErrorUsernameExist'];
        }

        $sqlUser = "SELECT COUNT(*) as count
				FROM %%ACCOUNTS_TO_USERS%% au
				INNER JOIN %%USERS%% u ON u.`id` = au.`user_id`
				WHERE u.universe = :universe AND au.account_id = :accountId AND au.end_time = 0";

        $countUsers = $db->selectSingle($sqlUser, [
            ':universe' => $universe,
            ':accountId' => $ACCOUNT['id'],
        ], 'count');

        if ($countUsers > 0) {
            $errors[] = $LNG['registerErrorUsernameExist'];
        }

        if (!empty($errors)) {
            $this->assign([
                'accountName' => $ACCOUNT['accountname'],
                'toplink'       => "account?page=account",
                'topname'       => sprintf($LNG['Back']),
            ]);
            $this->printMessage(new \Twig\Markup(implode("<br>\r\n", array_map(static fn($e) => htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8', false), $errors)), 'UTF-8'), [[
                'label' => $LNG['registerBack'],
                'url' => '#',
                'action' => 'history-back',
            ]]);
        }

        if ($config->ref_active == 1 && !empty($referralID)) {
            $sql = "SELECT COUNT(*) as state FROM %%USERS%% WHERE id = :referralID AND universe = :universe;";
            $Count = $db->selectSingle($sql, [
                ':referralID' => $referralID,
                ':universe' => $universe,
            ], 'state');

            if ($Count == 0) {
                $referralID = 0;
            }
        } else {
            $referralID = 0;
        }

        $validationKey = bin2hex(random_bytes(16));


        [$userID, $planetID] = $this->playerUtil()->createPlayer(
            $ACCOUNT['id'],
            $universe,
            $userName,
            $language,
            null,
            null,
            null,
            null,
            $ACCOUNT['authlevel'],
            $this->sessions()->getClientIp(),
            $stbSettingsUni,
            $colorSettingsUni,
            $missionSettingsUni,
            $timezone,
            $imageTheme,
            $cssTheme,
        );
        $LNG->includeData(['PUBLIC']);
        $senderName = $LNG['registerWelcomePMSenderName'];
        $subject 	= $LNG['registerWelcomePMSubject'];
        $message 	= sprintf($LNG['registerWelcomePMText'], $config->game_name, $universe);

        $this->playerUtil()->sendMessage($userID, 1, $senderName, MESSAGE_TYPE_GAME, $subject, $message, $this->state->runtime->timestamp, 1, $universe);

        $session	= $this->sessions()->create();
        $session->userId		= $userID;
        $session->universe      = $universe;
        $session->planetId      = 0;
        $session->adminAccess	= 0;
        $session->save();

        $this->http()->redirectTo('game');
    }
}
