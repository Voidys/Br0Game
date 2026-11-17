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
 * request via EndOfRequest), response headers, cookies and the sanitising input accessor _GP().
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

    /** $URL relative to the area's directory, e.g. "game?page=overview" or "./?code=3" (login) */
    public function redirectTo(string $URL, bool $external = false): never
    {
        if (!$external && str_starts_with($URL, './')) {
            $URL = substr($URL, 2);
        }
        throw EndOfRequest::withResponse(new RedirectResponse($external ? $URL : $this->state->runtime->httpPath . $URL));
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

    /**
     * Reads one request value (POST overrides GET) typed and sanitised by the type of $default: float (or $highnum)
     * -> float, int -> int, string -> trimmed text with line breaks normalised, NUL removed and HTML special
     * characters encoded (ASCII only unless $multibyte; invalid UTF-8 becomes ''), array -> the same per element
     * (integers when the default array starts with 0). Missing or mistyped input returns $default.
     * Because the value is already HTML-encoded, templates must not encode it again (see docs/templates.md).
     * @param mixed $default value returned when the parameter is absent; its type selects the conversion.
     * @param bool $multibyte allow UTF-8 characters in strings.
     * @param bool $highnum read as float even for integer defaults (large numbers).
     */
    public function _GP(string $name, mixed $default, bool $multibyte = false, bool $highnum = false): mixed
    {
        $input = $this->state->requestInput ?? [];
        if (!isset($input[$name])) {
            return $default;
        }

        if (is_float($default) || $highnum) {
            return (float) $input[$name];
        }

        if (is_int($default)) {
            return (int) $input[$name];
        }

        if (is_string($default)) {
            // an array (`?x[]=1`) where a string is expected counts as missing
            if (!is_scalar($input[$name])) {
                return $default;
            }

            return $this->_quote((string) $input[$name], $multibyte);
        }

        if (is_array($default) && is_array($input[$name])) {
            return $this->_quoteArray($input[$name], $multibyte, !empty($default) && $default[0] === 0);
        }

        return $default;
    }

    /**
     * Reads a request value (POST overrides GET) exactly as sent: no HTML encoding, trimming or charset changes. For
     * secrets (passwords) that are hashed or compared, where _GP() would alter the value.
     */
    public function rawInput(string $name): string
    {
        $value = ($this->state->requestInput ?? [])[$name] ?? '';

        return is_string($value) ? $value : '';
    }

    /** raw POST data of the request */
    public function post(): array
    {
        return $this->state->requestPost ?? [];
    }

    private function responseHeaders(): ResponseHeaderBag
    {
        if (!($this->state->responseHeaders ?? null) instanceof ResponseHeaderBag) {
            $this->state->responseHeaders = new ResponseHeaderBag();
        }

        return $this->state->responseHeaders;
    }

    private function _quoteArray(array $var, bool $multibyte, bool $onlyNumbers = false): array
    {
        $data	= [];
        foreach ($var as $key => $value) {
            if (is_array($value)) {
                $data[$key]	= $this->_quoteArray($value, $multibyte);
            } elseif ($onlyNumbers) {
                $data[$key]	= (int) $value;
            } else {
                $data[$key]	= $this->_quote($value, $multibyte);
            }
        }

        return $data;
    }

    private function _quote(string $var, bool $multibyte): string
    {
        $var	= str_replace(["\r\n", "\r", "\0"], ["\n", "\n", ''], $var);
        $var	= htmlspecialchars($var, ENT_QUOTES, 'UTF-8');
        $var	= trim($var);

        if ($multibyte) {
            if (!preg_match('/^./u', $var)) {
                $var = '';
            }
        } else {
            $var = preg_replace('/[\x80-\xFF]/', '?', $var); // no multibyte, allow only ASCII (0-127)
        }

        return $var;
    }
}
