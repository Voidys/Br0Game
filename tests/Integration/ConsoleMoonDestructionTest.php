<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Player\PlanetManager;
use App\Tests\Support\GameTestCase;

/**
 * Deleting a planet must not need a web session: moon destruction runs in console fleet processing
 * (app:fleets:run, the fleet cronjob), where PlanetManager::deletePlanet() used to load the session to reset the
 * selected planet and threw "No session available (console run?)", so the whole event was rolled back and re-rolled.
 */
final class ConsoleMoonDestructionTest extends GameTestCase
{
    public function testMoonIsDeletedWithoutRequestSession(): void
    {
        [$user, $planet] = $this->createPlayer();
        $planets = new PlanetManager($this->db, $this->configs, $this->state);
        $moonId = $planets->createMoon(1, (int) $planet['galaxy'], (int) $planet['system'], (int) $planet['planet'], (int) $user['id'], 20.0);
        $this->assertGreaterThan(0, $moonId);

        $request = $this->state->request;
        $this->state->request = null;
        try {
            $this->assertTrue($planets->deletePlanet($moonId));
        } finally {
            $this->state->request = $request;
        }

        $this->assertEmpty($this->db->selectSingle('SELECT id FROM %%PLANETS%% WHERE id = :id;', [':id' => $moonId]));
        $this->assertSame(0, (int) $this->loadPlanet((int) $planet['id'])['id_luna']);
    }
}
