<?php

namespace App\Controller;

/**
 * Controllers of game pages. Before such a controller runs, the game code is bootstrapped for
 * its area (see App\EventListener\GameControllerListener) and its result is collected like a
 * game request (EndOfRequest, echoed output, native headers; see GameResponseFactory).
 */
interface GameControllerInterface
{
    /** Runs after the game is bootstrapped, before the action (former page constructor work). */
    public function initialize(): void;
}
