<?php

namespace App\Controller\Game;

use App\Game\Core\AccountUtil;
use App\Game\Core\Session;
use App\Game\Core\WikiSsoCodeStore;

/**
 * Single sign-on bridge to the wiki: posts a one-time code to the wiki login URL.
 */
#[\App\Routing\GamePage('game.php', 'wikiLogin')]
#[\App\Routing\GamePage('game.php', 'wikiLogin', uniPrefix: true)]
class WikiLoginController extends AbstractGameController
{
    public static $requireModule = 0;

    protected $disableEcoSystem = true;

    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Single sign-on to the wiki: for a logged-in account stores a one-time code (WikiSsoCodeStore, with account id and name) and renders an auto-submitting
     * form to the wiki login URL from config/game.php. 403 without a session, 503 when no wiki SSO URL is configured.
     */
    public function show()
    {

        require $this->state->runtime->rootPath . 'config/game.php';

        $accountId = (int) $this->sessions()->load()->accountId;

        if ($accountId <= 0) {
            $this->http()->sendHeader('HTTP/1.1 403 Forbidden');
            throw new \App\Game\Core\EndOfRequest();
        }

        if (empty($wikiSsoUrl)) {
            $this->http()->sendHeader('HTTP/1.1 503 Service Unavailable');
            echo 'Wiki-SSO ist auf diesem Server nicht konfiguriert.';
            throw new \App\Game\Core\EndOfRequest();
        }

        $account = $this->accountUtil()->get_account($accountId);
        $accountname = $account['accountname'] ?? '';

        $code = $this->generateCode();
        WikiSsoCodeStore::create($wikiSsoCodeStore, $wikiSsoRedisUrl)->store($code, [
            'accountId'   => $accountId,
            'accountname' => $accountname,
            'iat'         => time(),
        ]);

        $this->renderAutoSubmitForm($code, $wikiSsoUrl);
        throw new \App\Game\Core\EndOfRequest();
    }

    private function generateCode()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function renderAutoSubmitForm($code, $wikiSsoUrl)
    {
        $url = htmlspecialchars($wikiSsoUrl, ENT_QUOTES);
        $codeEscaped = htmlspecialchars($code, ENT_QUOTES);

        echo <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Weiterleitung zum Wiki...</title></head>
<body>
    <form id="wikiSsoForm" method="post" action="{$url}">
        <input type="hidden" name="code" value="{$codeEscaped}">
        <noscript><button type="submit">Weiter zum Wiki</button></noscript>
    </form>
    <script>document.getElementById('wikiSsoForm').submit();</script>
</body>
</html>
HTML;
    }
}
