<?php

namespace App\Runtime;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use App\Game\Core\EndOfRequest;

/**
 * Runs legacy game code and turns what it printed and the headers/status it set via the
 * native PHP functions (header(), setcookie(), http_response_code()) into a Response.
 */
final class GameResponseFactory
{
    public function __construct(
        private readonly \App\Game\Core\ErrorReporter $errorReporter,
        private readonly \App\Game\Core\Singleton $state,
    ) {}

    /**
     * @param callable(): (Response|void|null) $code a Response it returns is used as is
     *                                             (plus natively set headers/cookies)
     */
    public function run(callable $code): Response
    {
        $level = ob_get_level();
        ob_start();
        $result = null;

        try {
            try {
                $result = $code();
            } catch (EndOfRequest $end) {
                $result = $end->getResponse();
                echo $end->getOutput();
            } catch (\Throwable $error) {
                $this->renderLegacyError($error);
            }
        } finally {
            // the game session goes back into the Symfony session before SessionListener saves it
            $session = $this->state->session ?? null;
            if ($session instanceof \App\Game\Core\Session) {
                $session->save();
            }
            $content = '';
            while (ob_get_level() > $level) {
                $content = ob_get_clean() . $content;
            }
        }

        if ($result instanceof Response) {
            return $this->withGameHeaders($this->withNativeHeaders($result, $content), false);
        }

        return $this->withGameHeaders($this->toResponse($content), true);
    }

    private function withNativeHeaders(Response $response, string $echoed): Response
    {
        if ($echoed !== '') {
            $response->setContent($echoed . $response->getContent());
        }
        foreach (headers_list() as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($name, 'X-Powered-By') === 0) {
                continue;
            }
            // headers chosen by the controller win over the bootstrap's defaults (Content-Type, ...)
            if (strcasecmp($name, 'Set-Cookie') === 0 || !$response->headers->has($name)) {
                $response->headers->set($name, $value, false);
            }
        }
        header_remove();

        return $response;
    }

    private function renderLegacyError(\Throwable $error): void
    {
        // The game's own error page (ErrorReporter); needs the game bootstrap (constants, helpers).
        if (!function_exists('makebr')) {
            throw $error;
        }

        try {
            $this->errorReporter->handleException($error);
        } catch (EndOfRequest $end) {
            echo $end->getOutput();
        }
    }

    /**
     * Headers, cookies and the status code the game set through HTTP (collected in the game state).
     * On a Response the game code built itself, its own headers win.
     */
    private function withGameHeaders(Response $response, bool $fromOutput): Response
    {
        $headers = $this->state->responseHeaders ?? null;
        if ($headers instanceof ResponseHeaderBag) {
            foreach ($headers->allPreserveCaseWithoutCookies() as $name => $values) {
                // defaults every ResponseHeaderBag carries, not set by the game
                if (strcasecmp($name, 'Date') === 0 || (strcasecmp($name, 'Cache-Control') === 0 && $values === ['no-cache, private'])) {
                    continue;
                }
                if ($fromOutput || !$response->headers->has($name)) {
                    $response->headers->set($name, $values);
                }
            }
            foreach ($headers->getCookies() as $cookie) {
                $response->headers->setCookie($cookie);
            }
            $this->state->responseHeaders = new ResponseHeaderBag();
        }
        $status = $this->state->responseStatus ?? null;
        if ($fromOutput && is_int($status)) {
            $response->setStatusCode($status);
        }
        $this->state->responseStatus = null;

        return $response;
    }

    private function toResponse(string $content): Response
    {
        $response = new Response($content, http_response_code() ?: 200);

        // Move natively set headers into the response so Symfony sends them exactly once.
        foreach (headers_list() as $line) {
            [$name, $value] = array_map('trim', explode(':', $line, 2)) + [1 => ''];
            if (strcasecmp($name, 'X-Powered-By') === 0) {
                continue;
            }
            $response->headers->set($name, $value, false);
        }
        header_remove();

        if (!$response->headers->has('Content-Type')) {
            $response->headers->set('Content-Type', 'text/html; charset=UTF-8');
        }

        return $response;
    }
}
