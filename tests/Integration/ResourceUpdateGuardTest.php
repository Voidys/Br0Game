<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\ResourceUpdate;
use App\Tests\Support\GameTestCase;

/**
 * ResourceUpdate::wouldUpdate() tells a page view whether the planet update would write anything; it must agree with
 * CalcResource(): when it says "no", CalcResource() with saving changes neither the planet nor the user row.
 */
final class ResourceUpdateGuardTest extends GameTestCase
{
    private function update(): ResourceUpdate
    {
        return new ResourceUpdate($this->db, $this->configs, $this->state);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>} user and planet whose last update is $secondsAgo old */
    private function playerUpdatedAgo(int $secondsAgo): array
    {
        [$user, $planet] = $this->createPlayer([], ['last_update' => $this->state->runtime->timestamp - $secondsAgo]);

        return [$user, $planet];
    }

    public function testNothingToDoInTheSameSecondAsTheLastUpdate(): void
    {
        [$user, $planet] = $this->playerUpdatedAgo(0);
        $now = $this->state->runtime->timestamp;

        $this->assertFalse($this->update()->wouldUpdate($user, $planet));
        $this->assertFalse($this->update()->wouldUpdate($user, $planet, $now));
        $this->assertTrue($this->update()->wouldUpdate($user, $planet, $now + 1));

        $this->update()->CalcResource($user, $planet, true);
        $this->assertEquals($planet, $this->loadPlanet((int) $planet['id']));
        $this->assertEquals($user, $this->loadUser((int) $user['id']));
    }

    public function testElapsedTimeMeansAnUpdate(): void
    {
        [$user, $planet] = $this->playerUpdatedAgo(60);

        $this->assertTrue($this->update()->wouldUpdate($user, $planet));
    }

    public function testVacationModeAndDestroyedPlanetsAreNeverUpdated(): void
    {
        [$user, $planet] = $this->playerUpdatedAgo(60);

        $this->assertTrue($this->update()->wouldUpdate($user, $planet));
        $this->assertFalse($this->update()->wouldUpdate(['urlaubs_modus' => 1] + $user, $planet));
        $this->assertFalse($this->update()->wouldUpdate($user, ['destruyed' => 1] + $planet));

        $this->update()->CalcResource(['urlaubs_modus' => 1] + $user, $planet, true);
        $this->assertEquals($planet, $this->loadPlanet((int) $planet['id']));
    }
}
