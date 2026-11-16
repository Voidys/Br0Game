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

    /**
     * Registration form for another universe (request: uni). Shows the registration state of the universe (closed, registration
     * only, login only, open; admins are not restricted) - closed and login-only universes end in an error page - and the
     * settings of the account's existing players that can be copied (colors, mission priorities, simulator settings).
     */
    public function show(): Response
    {
        $uniId = $this->http()->_GP('uni', '0');
        $LNG = $this->state->LNG;
        $ACCOUNT = $this->state->ACCOUNT;
        $LNG->includeData(['PUBLIC', 'INGAME', 'ACCOUNT']);
        if ((int) $uniId !== 0 && !$this->isUniverse($uniId)) {
            (new ErrorController())->withGameServicesOf($this)->showError($LNG['registerErrorUniClosed']);
        }
        $accountName = "";
        $status = "";
        $config = $this->configs->get($uniId);
        if ($config->uni_status == STATUS_CLOSED && $ACCOUNT['authlevel'] < 3) {
            $status = $LNG->format('uni_status_regclosed_gameclosed');
            (new ErrorController())->withGameServicesOf($this)->showError($LNG['uni_status_regclosed_gameclosed']);
        } elseif ($config->uni_status == STATUS_REG_ONLY && $ACCOUNT['authlevel'] < 3) {
            $status = $LNG->format('uni_status_regopen_gameclosed');
        } elseif ($config->uni_status == STATUS_LOGIN_ONLY && $ACCOUNT['authlevel'] < 3) {
            $status = $LNG->format('uni_status_regclosed_gameopen');
            (new ErrorController())->withGameServicesOf($this)->showError($LNG['uni_status_regclosed_gameopen']);
        } elseif ($config->uni_status == STATUS_OPEN && $ACCOUNT['authlevel'] < 3) {
            $status = $LNG->format('uni_status_regopen_gameopen');
        } else {
            $status = $LNG->format('uni_status_regopen_gameopen');
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
                'lang' => $LNG->getLanguageOptions(),
            ],
            'accountName' => $accountName,
            'universe' => $config->uni_name,
            'uniId' => $uniId,
            'status' => $status,
            'registerPasswordDesc' => $LNG->format('registerPasswordDesc', 6),
            'toplink'       => "account?page=account",
            'topname'       => $LNG->format('Back'),
            'userLang' => $ACCOUNT['lang'],
            'timezone' => $ACCOUNT['timezone'],
            'imageTheme' => Theme::resolveDefaultTheme($config->default_theme ?? null),
            'cssTheme' => Theme::resolveDefaultTheme($config->default_theme ?? null),
            'defaultUnis' => $defaultUnis,
            'default' => $defaultUnis['default'],
        ]);

        return $this->render('account/page.register.default.html.twig');
    }

    /**
     * Creates a player of the account in universe uni (mode=send). Request: uni, username, language, imageTheme, cssTheme,
     * timezone, colors, missionPrios, stbSettings ('default' or the universe id to copy from).
     * Refused when the universe does not accept registrations, the name is invalid or taken, or the account already has a player
     * there. Creates player and home planet through PlayerUtil::createPlayer (a referral stored on the account is applied to the first
     * player: ref_id/ref_bonus, then cleared), logs the player in and redirects to /game.
     */
    public function send()
    {
        $LNG = $this->state->LNG;
        $ACCOUNT = $this->state->ACCOUNT;
        $universe = $this->http()->_GP('uni', 0);
        $LNG->includeData(['PUBLIC', 'INGAME', 'ACCOUNT']);
        if ($universe != 0 && !$this->isUniverse($universe)) {
            $this->printMessage($LNG['registerErrorUniClosed'], [[
                'label' => $LNG['registerBack'],
                'url' => '#',
                'action' => 'history-back',
            ]]);
        }
        $config = $this->configs->get($universe);
        $this->assign([
            'accountName' => $ACCOUNT['accountname'],
            'universe' => $config->uni_name,
            'toplink'       => "account?page=account",
            'topname'       => $LNG->format('Back'),
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
        $timezone = (string) $this->http()->_GP('timezone', '');
        $timezone = in_array($timezone, \DateTimeZone::listIdentifiers(), true) ? $timezone : (string) $ACCOUNT['timezone'];
        $language = array_key_exists($language, $LNG->getAllowedLangs(false)) ? $language : $LNG->getLanguage();
        $colors = $this->http()->_GP('colors', 'default');
        $mission = $this->http()->_GP('missionPrios', 'default');
        $stb = $this->http()->_GP('stbSettings', 'default');

        $errors = $this->newPlayerErrors($userName, $universe);

        [$colorSettingsUni, $missionSettingsUni, $stbSettingsUni] = $this->settingsSourceUniverses($colors, $mission, $stb);

        if (!empty($errors)) {
            $this->abortWithErrors($errors);
        }

        $referralID = $this->validReferralId($universe);

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
        $this->applyReferral($referralID, $userID);
        $this->sendWelcomeMessage($userID, $universe);

        $session	= $this->sessions()->create();
        $session->userId		= $userID;
        $session->universe      = $universe;
        $session->planetId      = 0;
        $session->adminAccess	= 0;
        $session->save();

        $this->http()->redirectTo('game');
    }

    /** Is $uniId one of the universes of this installation? */
    private function isUniverse(mixed $uniId): bool
    {
        return in_array((int) $uniId, array_map('intval', $this->universe()->availableUniverses()), true);
    }

    /**
     * Ends the request with the error list as message page (back link = browser history).
     *
     * @param string[] $errors translated error messages
     */
    private function abortWithErrors(array $errors): never
    {
        $LNG = $this->state->LNG;
        $ACCOUNT = $this->state->ACCOUNT;

        $this->assign([
            'accountName' => $ACCOUNT['accountname'],
            'toplink'       => "account?page=account",
            'topname'       => $LNG->format('Back'),
        ]);
        $this->printMessage(new \Twig\Markup(implode("<br>\r\n", array_map(static fn($e) => htmlspecialchars((string) $e, ENT_QUOTES, 'UTF-8', false), $errors)), 'UTF-8'), [[
            'label' => $LNG['registerBack'],
            'url' => '#',
            'action' => 'history-back',
        ]]);
    }

    /**
     * Validates the new player's name for the universe: not empty, valid characters, not taken in the universe and the
     * account has no active player there yet. Reads USERS and ACCOUNTS_TO_USERS.
     *
     * @return string[] translated error messages
     */
    private function newPlayerErrors(string $userName, mixed $universe): array
    {
        $LNG = $this->state->LNG;
        $ACCOUNT = $this->state->ACCOUNT;
        $db = $this->db;

        $errors = [];

        if (empty($userName)) {
            $errors[] = $LNG['registerErrorUsernameEmpty'];
        }

        if (!$this->accountUtil()->isNameValid($userName)) {
            $errors[] = $LNG['registerErrorUsernameChar'];
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

        return $errors;
    }

    /**
     * Maps the "copy settings from universe" dropdowns (colors, mission priorities, STB settings) to a universe id as
     * string or 'default'. The dropdowns are keyed by universe id; PlayerUtil::createPlayer only copies from the
     * account's own players.
     *
     * @return array{0: string, 1: string, 2: string} universe of colors, mission priorities and STB settings
     */
    private function settingsSourceUniverses(mixed $colors, mixed $mission, mixed $stb): array
    {
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
        return [$colorSettingsUni, $missionSettingsUni, $stbSettingsUni];
    }

    /**
     * The referral stored on the account (ACCOUNTS.referralID) only applies when referrals are active and the recruiter
     * is a player of the target universe; otherwise 0.
     */
    private function validReferralId(mixed $universe): int
    {
        $ACCOUNT = $this->state->ACCOUNT;
        $config = $this->configs->get($universe);
        $db = $this->db;

        // referral carried over from the registration (ACCOUNTS.referralID); only valid for a player of that universe
        $referralID = (int) ($ACCOUNT['referralID'] ?? 0);
        if ($config->ref_active == 1 && $referralID > 0) {
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

        return $referralID;
    }

    /**
     * Marks the new player as recruited (`ref_id`, `ref_bonus`; the recruiter is rewarded later by ReferralCronjob) and
     * clears the referral on the account. No-op without a referral.
     */
    private function applyReferral(int $referralID, mixed $userID): void
    {
        $ACCOUNT = $this->state->ACCOUNT;
        $db = $this->db;

        if ($referralID > 0) {
            // the recruiter is rewarded by ReferralCronjob once this player reaches ref_minpoints
            $db->update('UPDATE %%USERS%% SET `ref_id` = :referralId, `ref_bonus` = 1 WHERE `id` = :userID;', [
                ':referralId' => $referralID,
                ':userID' => $userID,
            ]);
            $db->update('UPDATE %%ACCOUNTS%% SET `referralID` = 0 WHERE `id` = :accountID;', [':accountID' => $ACCOUNT['id']]);
        }
    }

    /**
     * Sends the welcome message (sender, subject and text from the PUBLIC texts) to the new player.
     */
    private function sendWelcomeMessage(mixed $userID, mixed $universe): void
    {
        $LNG = $this->state->LNG;
        $config = $this->configs->get($universe);

$LNG->includeData(['PUBLIC']);
$senderName = $LNG['registerWelcomePMSenderName'];
$subject 	= $LNG['registerWelcomePMSubject'];
$message 	= $LNG->format('registerWelcomePMText', $config->game_name);

$this->playerUtil()->sendMessage($userID, 1, $senderName, MESSAGE_TYPE_GAME, $subject, $message, $this->state->runtime->timestamp, 1, $universe);
    }
}
