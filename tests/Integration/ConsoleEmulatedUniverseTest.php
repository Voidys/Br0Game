<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Log;
use App\Game\Core\SessionManager;
use App\Game\Core\Universe;
use App\Tests\Support\GameTestCase;

/**
 * Console runs (app:fleets:run, the fleet cronjob) have no request session: the emulated universe falls back to the
 * current one instead of loading a session. Before, every transport arrival failed there with "No session available
 * (console run?)" (MissionCaseTransport writes a Log, whose constructor asks for the emulated universe).
 */
final class ConsoleEmulatedUniverseTest extends GameTestCase
{
    public function testEmulatedUniverseWithoutRequestIsTheCurrentOne(): void
    {
        $request = $this->state->request;
        $emulated = $this->state->universeEmulated;
        $this->state->request = null;
        $this->state->universeEmulated = null;
        try {
            $universe = new Universe($this->state, new SessionManager($this->db, $this->configs, $this->state));

            $this->assertSame($universe->current(), $universe->getEmulated());
            $this->assertNull($this->state->universeEmulated, 'not cached: the fleet cronjob switches the current universe');

            new Log($this->db, $this->configs, $this->state, '5'); // threw "No session available" before
        } finally {
            $this->state->request = $request;
            $this->state->universeEmulated = $emulated;
        }
    }
}
