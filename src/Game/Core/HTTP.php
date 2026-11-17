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

namespace App\Game\Core;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

/**
 * Response and input helpers of the game code on top of the Symfony request/response: redirects (they end the
 * request via EndOfRequest), response headers, cookies and and the status line.
 */
class HTTP
{
    /**
     * Request input and response headers of the game, on top of the Symfony request: GameKernel puts
     * the request data into the game state (requestInput = POST over GET), headers, cookies and the
     * status code set here are applied to the Response by GameResponseFactory.
     */
    public function __construct(
        protected Singleton $state,
    ) {}

    /** $URL relative to the area's directory, e.g. "game/overview" or "./?code=3" (login) */
    public function redirectTo(string $URL, bool $external = false): never
    {
        throw EndOfRequest::withResponse($this->redirectResponse($URL, $external));
    }

    /** the redirect redirectTo() ends the request with, for a mode method that returns it */
    public function redirectResponse(string $URL, bool $external = false): RedirectResponse
    {
        if (!$external && str_starts_with($URL, './')) {
            $URL = substr($URL, 2);
        }

        return new RedirectResponse($external ? $URL : $this->state->runtime->httpPath . $URL);
    }

    /** a header ("Name", "value") or a status line ("HTTP/1.1 503 Service Unavailable") */
    public function sendHeader(string $name, ?string $value = null): void
    {
        if ($value === null && preg_match('#^HTTP/\S+\s+(\d{3})#', $name, $match)) {
            $this->state->responseStatus = (int) $match[1];

            return;
        }
        $this->responseHeaders()->set($name, (string) $value);
    }

    /**
     * Redirects to the entry page of another universe (<host><base>uni<N>/).
     */
    public function redirectToUniverse(int $universe): never
    {
        $this->redirectTo($this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase . "uni" . $universe . "/" . $this->state->runtime->httpFile, true);
    }

    /** same defaults as PHP's setcookie(): no HttpOnly, no SameSite */
    public function sendCookie(
        string $name,
        string $value = "",
        ?int $toTime = null,
        string $path = '/',
        ?string $domain = null,
        bool $secure = false,
        bool $httpOnly = false,
        ?string $sameSite = null,
    ): void {
        $this->responseHeaders()->setCookie(Cookie::create($name, $value, $toTime ?? 0, $path, $domain, $secure, $httpOnly, false, $sameSite));
    }

    private function responseHeaders(): ResponseHeaderBag
    {
        if (!($this->state->responseHeaders ?? null) instanceof ResponseHeaderBag) {
            $this->state->responseHeaders = new ResponseHeaderBag();
        }

        return $this->state->responseHeaders;
    }
}
