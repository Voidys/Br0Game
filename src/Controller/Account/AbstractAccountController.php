<?php

/**
 * pr0game powered by steemnova
 * Accounts
 * (c) 2024 reflexrecon
 */

namespace App\Controller\Account;

use App\Controller\GameControllerInterface;
use App\Controller\GuardsModule;
use App\Controller\ModuleGuard;
use App\Controller\RendersPages;
use App\Game\Core\EndOfRequest;
use Symfony\Component\HttpFoundation\Response;
use DateTime;
use DateTimeZone;
use Exception;

/**
 * Base for the account lobby controllers (/account/...): universe list, account settings,
 * the board. Every action has its own route (GameRoute); pages render with RendersPages and get the
 * account navigation and clock settings of the account layout.
 */
abstract class AbstractAccountController implements GameControllerInterface, ModuleGuard
{
    use \App\Game\Core\GameUtilities;

    use \App\Controller\GameServices;

    use GuardsModule;

    use RendersPages;

    protected static function templateArea(): string
    {
        return 'account';
    }

    /** Message page for a request over the in-game request budget (RateLimitListener): no page code runs, status 429 is set by the listener. */
    public function showRateLimited(int $retryAfter): never
    {
        $this->state->LNG->includeData(['INGAME']);
        $this->setWindow('full');
        $this->printMessage($this->state->LNG->format('rl_too_many_requests', $retryAfter));
    }

    /** Error page of the area for GuardsModule (module inactive); ends the request. */
    protected function printPageError(string $message): never
    {
        $this->printMessage($message);
    }

    /**
     * Guests (no account in the session) are sent to the start page; everything else is passed on to the page.
     */
    public function initialize(): void
    {
        $this->setWindow('full');
        // only the board is open to visitors without login (account stub with id 0)
        if (empty($this->state->ACCOUNT['id']) && !$this instanceof BoardController) {
            $this->redirectTo('./');
        }
    }

    /**
     * The message page of the area.
     *
     * @param string|\Twig\Markup $message plain text is escaped, pass Twig\Markup for HTML
     * @param list<array{url: string, label: string}>|null $redirectButtons links under the message
     * @param array{0: string, 1: int}|null $redirect [url, seconds] automatic redirect
     * @param bool $fullSide false: popup window
     */
    protected function messageResponse($message, $redirectButtons = null, $redirect = null, $fullSide = true): Response
    {
        $context = [
            'message' => $message,
            'redirectButtons' => $redirectButtons,
        ];

        if (isset($redirect)) {
            $context += ['goto' => $redirect[0], 'gotoinsec' => $redirect[1]];
        }

        if (!$fullSide) {
            $this->setWindow('popup');
        }

        return $this->render('account/error.default.html.twig', $context);
    }

    /**
     * Ends the request with the message page: for initialize() and helpers that cannot return the Response (an action
     * returns messageResponse()).
     *
     * @param string|\Twig\Markup $message
     * @param list<array{url: string, label: string}>|null $redirectButtons
     * @param array{0: string, 1: int}|null $redirect
     * @param bool $fullSide false: popup window
     */
    protected function printMessage($message, $redirectButtons = null, $redirect = null, $fullSide = true): never
    {
        throw EndOfRequest::withResponse($this->messageResponse($message, $redirectButtons, $redirect, $fullSide));
    }

    /**
     * Variables of the account shell (page title, account name, theme, server time in the account's timezone).
     * Nothing is added for ajax windows.
     */
    protected function layoutData(string $template): array
    {
        $ACCOUNT = $this->state->ACCOUNT;
        $THEME = $this->state->THEME;
        $LNG = $this->state->LNG;
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
                $deletion = $LNG->format('tn_delete_mode', $this->viewHelpers()->formatDate($LNG['php_tdformat'], $this->accountUtil()->getDeletionDate($ACCOUNT['db_deaktjava'], $config), $ACCOUNT['timezone']));
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
                'themeSettings' => $THEME->getStyleSettings(),
            ];
        }

        if (!empty($ACCOUNT['guest'])) {
            // start page frame (account/layout.site extends login/layout.base)
            $data += [
                'gameName'          => $config->game_name,
                'languages'         => $this->state->LNG->getLanguageOptions(),
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
        ]);
    }

    /**
     * Ends the request with a JSON answer (helpers; an action returns jsonResponse()).
     */
    protected function sendJSON($data): never
    {
        throw EndOfRequest::withResponse($this->jsonResponse($data));
    }

    /**
     * Ends the request with a redirect to $url (initialize() and helpers; an action returns redirectResponse()).
     */
    protected function redirectTo($url): never
    {
        throw EndOfRequest::withResponse($this->redirectResponse($url));
    }
}
