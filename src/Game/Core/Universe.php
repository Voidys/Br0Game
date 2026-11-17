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

use Exception;

/**
 * Which universe the request belongs to: current (from login parameter, admin session, host name or URL
 * /uniN/), the emulated universe of admins and the list of available universes. All state is kept in the game
 * state, shared by every Universe object of a request.
 */
class Universe
{
    /**
     * Universe state (current, emulated, available ids) lives in the game state, shared by every
     * Universe object of the request. No database here: the installer needs the current universe
     * before a config exists; the session (emulated universe, admin only) is resolved on demand.
     */
    public function __construct(
        protected Singleton $state,
        private ?SessionManager $sessions = null,
    ) {}

    private function sessionManager(): SessionManager
    {
        return $this->sessions ?? throw new \LogicException('The emulated universe needs the session: use GameUtilities::universe().');
    }

    /**
     * Return the current universe id.
     *
     * @return int
     */

    public function current()
    {
        if (is_null($this->state->universeCurrent)) {
            $this->state->universeCurrent = $this->defineCurrentUniverse();
        }

        return $this->state->universeCurrent;
    }

    /**
     * Registers a universe id as available (called while the configuration is loaded).
     */
    public function add($universe)
    {
        $available = $this->availableUniverses();
        $available[] = $universe;
        $this->state->universeAvailable = $available;
    }

    /**
     * Removes a universe id from the available list.
     */
    public function remove($universe)
    {
        $this->state->universeAvailable = array_values(array_filter(
            $this->availableUniverses(),
            static function ($id) use ($universe) {
                return (int) $id !== (int) $universe;
            },
        ));
    }

    /**
     * Universe an admin currently acts in (stored in the session; defaults to the current one).
     */
    public function getEmulated()
    {
        if (is_null($this->state->universeEmulated)) {
            $session	= $this->sessionManager()->load();
            if (isset($session->emulatedUniverse)) {
                $this->setEmulated($session->emulatedUniverse);
            } else {
                $this->setEmulated($this->current());
            }
        }

        return $this->state->universeEmulated;
    }

    /**
     * Switches the admin's emulated universe and stores it in the session. @throws Exception for an unknown universe id.
     */
    public function setEmulated($universeId)
    {
        if (!$this->exists($universeId)) {
            throw new Exception('Unknown universe ID: ' . $universeId);
        }

        $session	= $this->sessionManager()->load();
        $session->emulatedUniverse	= $universeId;
        $session->save();

        $this->state->universeEmulated	= $universeId;

        return true;
    }

    /**
     * Find current universe id using cookies, get parameter or session keys.
     *
     * @return int
     */

    private function defineCurrentUniverse()
    {
        $universe = null;
        if ($this->state->runtime->mode === 'INSTALL' || $this->state->runtime->mode === 'UPGRADE') {
            // Installer are always in the first universe.
            return ROOT_UNI;
        }

        if (count($this->availableUniverses()) != 1) {
            if ($this->state->runtime->mode == 'LOGIN') {
                if (isset($this->state->requestInput['uni'])) {
                    $universe = (int) $this->state->requestInput['uni'];
                }
            } elseif ($this->state->runtime->mode == 'ADMIN' && $this->sessions?->attribute('admin_uni') !== null) {
                $universe = (int) $this->sessions->attribute('admin_uni');
            }

            if (is_null($universe)) {
                if (UNIS_WILDCAST) {
                    $temp = explode('.', $this->state->requestServer['HTTP_HOST']);
                    $temp = substr($temp[0], 3);
                    if (is_numeric($temp)) {
                        $universe = $temp;
                    } else {
                        $universe = ROOT_UNI;
                    }
                } else {
                    if (isset($this->state->requestServer['REDIRECT_UNI'])) {
                        // Apache - faster then preg_match
                        $universe = $this->state->requestServer["REDIRECT_UNI"];
                    } elseif (isset($this->state->requestServer['REDIRECT_REDIRECT_UNI'])) {
                        // Patch for www.top-hoster.de - Hoster
                        $universe = $this->state->requestServer["REDIRECT_REDIRECT_UNI"];
                    } elseif (preg_match('!/uni([0-9]+)/!', $this->state->runtime->httpPath, $match)) {
                        if (isset($match[1])) {
                            $universe = $match[1];
                        }
                    } else {
                        $universe = ROOT_UNI;
                    }
                }

                if (!isset($universe) || !$this->exists($universe)) {
                    (new HTTP($this->state))->redirectToUniverse(ROOT_UNI);
                }
            }
        } else {
            if ($this->state->runtime->httpRoot != $this->state->runtime->httpBase) {
                (new HTTP($this->state))->redirectTo($this->state->runtime->protocol . $this->state->runtime->httpHost . $this->state->runtime->httpBase . $this->state->runtime->httpFile, true);
            }
            if ($this->state->runtime->mode == 'LOGIN') {
                if (isset($this->state->requestInput['uni'])) {
                    $universe = (int) $this->state->requestInput['uni'];
                }
            }
            if (is_null($universe)) {
                $universe = ROOT_UNI;
            }
        }

        return $universe;
    }

    /**
     * Return an array of all universe ids
     *
     * @return array
     */

    public function availableUniverses()
    {
        return $this->state->universeAvailable ?? [];
    }

    /**
     * Find current universe id using cookies, get parameter or session keys.
     *
     * @param int universe id
     *
     * @return int
     */

    public function exists($universeId)
    {
        return in_array($universeId, $this->availableUniverses());
    }
}
