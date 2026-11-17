<?php

namespace App\Controller\Game;

use App\Game\Core\Session;
use App\Game\Core\WikiSsoCodeStore;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Single sign-on bridge to the wiki: posts a one-time code to the wiki login URL.
 */
class WikiLoginController extends AbstractGameController
{
    private ?\App\Security\CspNonce $cspNonce = null;

    /** the response's CSP nonce: the hand-off page needs a nonce for its auto-submit script and the wiki as form target */
    #[\Symfony\Contracts\Service\Attribute\Required]
    public function setCspNonce(\App\Security\CspNonce $cspNonce): void
    {
        $this->cspNonce = $cspNonce;
    }

    protected $disableEcoSystem = true;

    /**
     * Single sign-on to the wiki: for a logged-in account stores a one-time code (WikiSsoCodeStore, with account id and name) and renders an auto-submitting
     * form to the wiki login URL from config/game.php. 403 without a session, 503 when no wiki SSO URL is configured.
     */
    #[GameRoute('game', 'game/wiki-login', 'wikiLogin')]
    public function show(): Response
    {
        require $this->state->runtime->rootPath . 'config/game.php';

        $accountId = (int) $this->sessions()->load()->accountId;

        if ($accountId <= 0) {
            return new Response('', Response::HTTP_FORBIDDEN);
        }

        if (empty($wikiSsoUrl)) {
            return new Response('Wiki-SSO ist auf diesem Server nicht konfiguriert.', Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $account = $this->accountUtil()->get_account($accountId);
        $accountname = $account['accountname'] ?? '';

        $code = $this->generateCode();
        WikiSsoCodeStore::create($wikiSsoCodeStore, $wikiSsoRedisUrl)->store($code, [
            'accountId'   => $accountId,
            'accountname' => $accountname,
            'iat'         => time(),
        ]);

        return $this->autoSubmitForm($code, $wikiSsoUrl);
    }

    private function generateCode()
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * The hand-off page of the wiki single sign-on: a form that POSTs the one-time code to the configured
     * wiki URL and submits itself. The Content-Security-Policy normally only allows forms to this site and nonce
     * scripts, so the wiki origin is opened as form target for this response and the script carries the nonce;
     * the submit button stays visible as fallback when the script does not run.
     *
     * @param string $code       one-time code stored in WikiSsoCodeStore, redeemed by the wiki through internal_sso_resolve
     * @param string $wikiSsoUrl the wiki's SSO endpoint (config/game.php $wikiSsoUrl)
     */
    private function autoSubmitForm($code, $wikiSsoUrl): Response
    {
        $url = htmlspecialchars($wikiSsoUrl, ENT_QUOTES);
        $codeEscaped = htmlspecialchars($code, ENT_QUOTES);
        $nonce = '';
        if ($this->cspNonce !== null) {
            $this->cspNonce->allowSource('form-action', $wikiSsoUrl);
            $nonce = htmlspecialchars($this->cspNonce->value(), ENT_QUOTES);
        }

        return new Response(<<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>Weiterleitung zum Wiki...</title></head>
<body>
    <form id="wikiSsoForm" method="post" action="{$url}">
        <input type="hidden" name="code" value="{$codeEscaped}">
        <button type="submit">Weiter zum Wiki</button>
    </form>
    <script nonce="{$nonce}">document.getElementById('wikiSsoForm').submit();</script>
</body>
</html>
HTML);
    }
}
