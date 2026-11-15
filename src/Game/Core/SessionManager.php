<?php

namespace App\Game\Core;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;

/**
 * Starts, wakes and creates the game session (formerly the static side of Session). The current
 * Session lives in the game state ($state->session), so every SessionManager of a request sees
 * the same one; Session objects get the services attached after create/unserialize.
 */
class SessionManager
{
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /** @internal for Session: request data and game state */
    public function gameState(): Singleton
    {
        return $this->state;
    }

    /**
     * The Symfony session of the current request (storage, cookie and lifetime: framework.session,
     * handler: SESSION_HANDLER_DSN). The game's Session object lives in its "obj" attribute.
     */
    public function symfonySession(): SessionInterface
    {
        $request = $this->state->request ?? null;
        if (!$request instanceof Request || !$request->hasSession()) {
            throw new \LogicException('No session available (console run?).');
        }

        return $request->getSession();
    }

    public function id(): string
    {
        return $this->symfonySession()->getId();
    }

    public function name(): string
    {
        return $this->symfonySession()->getName();
    }

    /** an attribute of the Symfony session, null without a request session */
    public function attribute(string $name): mixed
    {
        $request = $this->state->request ?? null;

        return $request instanceof Request && $request->hasSession() ? $request->getSession()->get($name) : null;
    }

    public function getClientIp()
    {
        $keys = ['HTTP_CLIENT_IP','HTTP_X_FORWARDED_FOR','HTTP_X_FORWARDED','HTTP_FORWARDED_FOR','HTTP_FORWARDED','REMOTE_ADDR'];
        foreach ($keys as $key) {
            if (empty($this->state->requestServer[$key])) continue;
            $value = $this->state->requestServer[$key];
            if ($key === 'HTTP_X_FORWARDED_FOR') {
                $parts = array_map('trim', explode(',', $value));
                foreach ($parts as $ip) {
                    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                        return $ip;
                    }
                }
                return trim($parts[0]); // Fallback: erstes Element
            }
            if (filter_var($value, FILTER_VALIDATE_IP)) {
                return $value;
            }
        }
        return 'UNKNOWN';
    }

    /**
     * Create an empty session
     *
     * @return Session
     */
    public function create()
    {
        if (!$this->existsActiveSession()) {
            $this->symfonySession()->start();
            $session = (new Session())->attach($this, $this->db);
            $this->state->session = $session;
        }

        return $this->state->session;
    }

    /**
     * Wake an active session
     *
     * @return Session
     */
    public function load()
    {
        if (!$this->existsActiveSession()) {
            $symfonySession = $this->symfonySession();
            $symfonySession->start();
            if ($symfonySession->has('obj')) {
                $session = unserialize($symfonySession->get('obj'), ['allowed_classes' => [Session::class]]);
                $session->attach($this, $this->db);
                $this->state->session = $session;
            } else {
                $this->create();
            }
        }

        return $this->state->session;
    }

    /** writes the game session into the Symfony session; GameResponseFactory calls it before the response */
    public function saveCurrent(): void
    {
        $session = $this->state->session ?? null;
        if ($session instanceof Session) {
            $session->save();
        }
    }

    /**
     * Check if an active session exists
     *
     * @return bool
     */
    public function existsActiveSession()
    {
        return isset($this->state->session);
    }
}
