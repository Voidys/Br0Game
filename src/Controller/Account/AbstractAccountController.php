<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use App\Controller\GameControllerInterface;
use App\Controller\PageModeDispatch;
use App\Controller\RendersPages;
use App\Game\Core\EndOfRequest;
use DateTime;
use DateTimeZone;
use Exception;

/**
 * Base for the account lobby controllers (/account?page=...): universe list, account settings,
 * the board. ?mode= is dispatched by PageModeDispatch; pages render with RendersPages and get the
 * account navigation and clock settings of the account layout.
 */
abstract class AbstractAccountController implements GameControllerInterface
{
    use \App\Game\Core\GameUtilities;

    use \App\Controller\GameServices;

    use PageModeDispatch;

    use RendersPages;

    public static function getGameMode(): string
    {
        return 'ACCOUNT';
    }

    public static function getEntryScript(): string
    {
        return 'account.php';
    }

    protected static function templateArea(): string
    {
        return 'account';
    }

    protected function printPageError(string $message): never
    {
        $this->printMessage($message);
    }

    public function initialize(): void
    {
        $this->setWindow('full');
        // only the board is open to visitors without login (account stub with id 0)
        if (empty($this->state->ACCOUNT['id']) && !$this instanceof BoardController) {
            $this->redirectTo('./');
        }
    }

    protected function getQueryString()
    {
        $queryString = [];
        $page = $this->http()->_GP('page', '');

        if (!empty($page)) {
            $queryString['page'] = $page;
        }

        $mode = $this->http()->_GP('mode', '');
        if (!empty($mode)) {
            $queryString['mode'] = $mode;
        }

        $id = $this->http()->_GP('id', '');
        if (!empty($id)) {
            $queryString['id'] = $id;
        }

        return http_build_query($queryString);
    }

    /**
     * Shows a message page and ends the request.
     *
     * @param string|\Twig\Markup $message plain text is escaped, pass Twig\Markup for HTML
     * @param list<array{url: string, label: string}>|null $redirectButtons links under the message
     * @param array{0: string, 1: int}|null $redirect [url, seconds] automatic redirect
     * @param bool $fullSide false: popup window
     */
    protected function printMessage($message, $redirectButtons = null, $redirect = null, $fullSide = true): never
    {
        $this->assign([
            'message' => $message,
            'redirectButtons' => $redirectButtons,
        ]);

        if (isset($redirect)) {
            $this->assign(['goto' => $redirect[0], 'gotoinsec' => $redirect[1]]);
        }

        if (!$fullSide) {
            $this->setWindow('popup');
        }

        $this->display('account/error.default.html.twig');
    }

    protected function layoutData(string $template): array
    {
        $ACCOUNT = & $this->state->ACCOUNT;
        $THEME = & $this->state->THEME;
        $LNG = & $this->state->LNG;
        $config = $this->configs->get();

        $data = [];
        if ($this->getWindow() !== 'ajax') {
            $LNG->includeData(['INGAME', 'ACCOUNT']);
            $serverTime = new DateTime('now');
            $userTime = $serverTime;
            if (isset($ACCOUNT['timezone'])) {
                try {
                    $userTime = new DateTime('now', new DateTimeZone($ACCOUNT['timezone']));
                } catch (Exception $e) {
                    $userTime = $serverTime;
                }
            }

            $deletion = false;
            if ($ACCOUNT['db_deaktjava']) {
                $deletion = sprintf($LNG['tn_delete_mode'], $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->accountUtil()->getDeletionDate($ACCOUNT['db_deaktjava'], $config)), $ACCOUNT['timezone']);
            }
            $data = [
                'delete' => $deletion,
                'authlevel' => $ACCOUNT['authlevel'],
                'userID' => $ACCOUNT['id'],
                'bodyclass' => $this->getWindow(),
                'game_name' => $config->game_name,
                'uni_name' => $config->uni_name,
                'debug' => $config->debug,
                'version' => $config->version,
                'date' => explode("|", date('Y\|n\|j\|G\|i\|s\|Z', $this->state->runtime->timestamp)),
                'REV' => substr($config->version, -4),
                'Offset' => $userTime->getOffset() - $serverTime->getOffset(),
                'queryString' => $this->getQueryString(),
                'themeSettings' => $THEME->getStyleSettings(),
            ];
        }

        if (!empty($ACCOUNT['guest'])) {
            // start page frame (account/layout.site extends login/layout.base)
            $data += [
                'gameName'          => $config->game_name,
                'languages'         => (new \App\Game\Core\LanguageCatalog())->getAllowedLangs(false),
                'isMultiUniverse'   => count($this->universe()->availableUniverses()) > 1,
                'unisWildcast'      => UNIS_WILDCAST,
                'referralEnable'    => $config->ref_active,
            ];
        }

        return array_replace($data, [
            'lang'              => $ACCOUNT['lang'],
            'accountname'       => $ACCOUNT['accountname'],
            'dpath'             => $THEME->getTheme(),
            'themeCssPath'      => $THEME->getThemeCssPath(),
            'basepath'          => $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase,
            'TIMEZONESTRING'    => $ACCOUNT['timezone'],
            'signalColors'      => $ACCOUNT['signalColors'],
            'captchakey'        => $config->recaptcha_pub_key,
        ]);
    }

    protected function sendJSON($data)
    {
        // $this->updatePlanetTime();
        echo json_encode($data);
        throw new EndOfRequest();
    }

    protected function redirectTo($url)
    {
        // $this->updatePlanetTime();
        $this->http()->redirectTo($url);
        throw new EndOfRequest();
    }
}
