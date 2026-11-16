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

use App\Controller\GameControllerInterface;
use App\Controller\PageModeDispatch;
use App\Controller\RendersPages;
use App\Game\Core\EndOfRequest;

/**
 * Base for the public pages (start page, registration, news, hall of fame, ...: /?page=...).
 *
 * The window is $defaultWindow ("normal", the start page frame), or "ajax" for XHR requests.
 * ?mode= is dispatched by PageModeDispatch; pages render with RendersPages and get the game name,
 * language and universe data of the login layout. save() runs before every response.
 */
abstract class AbstractLoginController implements GameControllerInterface
{
    use \App\Game\Core\GameUtilities;

    use \App\Controller\GameServices;

    use PageModeDispatch;

    use RendersPages {
        render as private renderPage;
    }

    public static function getGameMode(): string
    {
        return 'LOGIN';
    }

    public static function getEntryScript(): string
    {
        return 'index.php';
    }

    protected static function templateArea(): string
    {
        return 'login';
    }

    protected function printPageError(string $message): never
    {
        $this->printMessage($message);
    }

    /** window of non-ajax requests (layout.<window>.html.twig) */
    public $defaultWindow = 'normal';

    public function initialize(): void
    {
        if (!$this->state->runtime->ajax) {
            $this->setWindow($this->defaultWindow);
        } else {
            $this->setWindow('ajax');
        }
    }

    protected function getUniverseSelector()
    {
        $universeSelect	= [];
        foreach ($this->universe()->availableUniverses() as $uniId) {
            $universeSelect[$uniId]	= $this->configs->get($uniId)->uni_name;
        }
        return $universeSelect;
    }

    protected function getQueryString()
    {
        $queryString	= [];
        $page			= $this->http()->_GP('page', '');

        if (!empty($page)) {
            $queryString['page']	= $page;
        }

        $mode			= $this->http()->_GP('mode', '');
        if (!empty($mode)) {
            $queryString['mode']	= $mode;
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
            'message'			=> $message,
            'redirectButtons'	=> $redirectButtons,
        ]);

        if (isset($redirect)) {
            $this->assign(['goto' => $redirect[0], 'gotoinsec' => $redirect[1]]);
        }

        if (!$fullSide) {
            $this->setWindow('popup');
        }

        $this->display('login/error.default.html.twig');
    }

    /** hook run before every response (pages that write data on the way out) */
    protected function save() {}

    protected function render(string $template): \Symfony\Component\HttpFoundation\Response
    {
        $this->save();

        return $this->renderPage($template);
    }

    protected function layoutData(string $template): array
    {
        $LNG = $this->state->LNG;
        $config	= $this->configs->get();

        $data = [];
        if ($this->getWindow() !== 'ajax') {
            $data = [
                'gameName' 				=> $config->game_name,
                'mailEnable'			=> $config->mail_active,
                'referralEnable'		=> $config->ref_active,
                'lang'					=> $LNG->getLanguage(),
                'UNI'					=> $this->universe()->current(),
                'version'				=> $config->version,
                'REV'					=> substr($config->version, -4),
                'languages'				=> (new \App\Game\Core\LanguageCatalog())->getAllowedLangs(false),
            ];
        }

        if (UNIS_WILDCAST) {
            $hostParts = explode('.', $this->state->runtime->httpHost);
            if (preg_match('/uni[0-9]+/', $hostParts[0])) {
                array_shift($hostParts);
            }
            $host = implode('.', $hostParts);
            $basePath = $this->state->runtime->protocol . $host . $this->state->runtime->httpBase;
        } else {
            $basePath = $this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase;
        }

        return array_replace($data, [
            'lang'    			=> $LNG->getLanguage(),
            'bodyclass'			=> $this->getWindow(),
            'basepath'			=> $basePath,
            'isMultiUniverse'	=> count($this->universe()->availableUniverses()) > 1,
            'unisWildcast'		=> UNIS_WILDCAST,
        ]);
    }

    protected function sendJSON($data)
    {
        $this->save();
        echo json_encode($data);
        throw new EndOfRequest();
    }

    protected function redirectTo($url)
    {
        $this->save();
        $this->http()->redirectTo($url);
        throw new EndOfRequest();
    }

    /** sends the browser to $url with a self-submitting POST form */
    protected function redirectPost($url, $postFields): never
    {
        $this->assign([
            'url'    		=> $url,
            'postFields'	=> $postFields,
        ]);

        $this->display('login/info.redirectPost.html.twig');
    }
}
