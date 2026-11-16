<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Missions\MissionCasePiratebaseAttack;
use App\Tests\Support\GameTestCase;

/**
 * After a pirate base fight, a destroyed attacking fleet is deleted and dropped from the fleet list that the loot and
 * the report use, exactly like AbstractBattleMission::applyLosses() does for normal attacks.
 */
final class PirateBaseAttackFleetsTest extends GameTestCase
{
    public function testDestroyedAttackerFleetLeavesTheList(): void
    {
        $mission = new MissionCasePiratebaseAttack(['id' => 900001, 'group' => 0], $this->db, $this->configs, $this->state);
        $fleets = [
            900001 => ['unit' => [202 => 0]],
            900002 => ['unit' => [202 => 5]],
            0 => ['unit' => [202 => 3]],
        ];

        $method = new \ReflectionMethod($mission, 'updateFleetsAfterBattle');
        $method->invokeArgs($mission, [&$fleets, 'report']);

        $this->assertSame([900002, 0], array_keys($fleets), 'the destroyed fleet is gone, the survivor and the base stay');
    }
}
