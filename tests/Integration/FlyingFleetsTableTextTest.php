<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\FlyingFleetsTable;
use App\Tests\Support\GameTestCase;

/**
 * Pins the translated HTML line of every fleet event (FlyingFleetsTable::getEventData): all missions x outward/return/
 * hold x the viewer being the owner, the target or a bystander (with and without an ACS group) x normal and phalanx
 * view x low and high espionage tech. The golden value is a hash of all lines (and event times), captured before the
 * long method was split into steps; a changed hash means some wording, class or popup changed.
 */
final class FlyingFleetsTableTextTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'all event lines' => [1152, 'd76a8f4b62d3fedfd330ceb7aad7a395'],
    ];
    // GOLDEN-END

    private function table(int $viewer, bool $phalanx): FlyingFleetsTable
    {
        $table = new FlyingFleetsTable($this->db, $this->configs, $this->state);
        $table->setUser($viewer);
        $table->setPlanet(1);
        if ($phalanx) {
            $table->setPhalanxMode();
        }

        return $table;
    }

    public function testEveryEventLineStaysTheSame(): void
    {
        [$user] = $this->createPlayer(['spy_tech' => 1]);
        // the wording must not depend on the language an earlier test left in the game state
        $previousLng = $this->state->LNG;
        $LNG = new \App\Game\Core\Language($this->configs, 'en');
        $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
        $this->state->LNG = $LNG;
        $missions = [MISSION_ATTACK, MISSION_ACS, MISSION_TRANSPORT, MISSION_STATION, MISSION_HOLD, MISSION_SPY, MISSION_COLONISATION,
            MISSION_RECYCLING, MISSION_DESTRUCTION, MISSION_MISSILE, MISSION_EXPEDITION, MISSION_TRADE, MISSION_TRANSFER,
            MISSION_PIRATEBASE_ATTACK, MISSION_PIRATEBASE_SPY, MISSION_PIRATEBASE_RECYCLING];
        $lines = [];
        foreach ([1, 9] as $spyTech) {
            $this->state->USER = $user;
            $this->state->USER['spy_tech'] = $spyTech;
            foreach ([false, true] as $phalanx) {
                foreach ([[100, 200, 200], [200, 100, 100], [300, 100, 200]] as [$viewer, $owner, $targetOwner]) {
                    foreach ([0, 5] as $group) {
                        foreach ($missions as $mission) {
                            foreach ([FLEET_OUTWARD, FLEET_RETURN, FLEET_HOLD] as $status) {
                                $row = [
                                    'id' => 77, 'owner' => $owner, 'target_owner' => $targetOwner, 'group' => $group, 'mission' => $mission,
                                    'start_type' => 1, 'end_type' => 3, 'own_planetname' => 'Home <b>', 'target_planetname' => 'Away',
                                    'own_username' => 'Fl<y>er', 'ship_amount' => 12, 'ships' => '202,10;203,2',
                                    'resource_metal' => 1500, 'resource_crystal' => 0, 'resource_deuterium' => 77,
                                    'start_galaxy' => 1, 'start_system' => 2, 'start_planet' => 3, 'end_galaxy' => 4, 'end_system' => 5, 'end_planet' => 6,
                                    'arrival_time' => 1000, 'stay_time' => 2000, 'return_time' => 3000,
                                ];
                                $result = $this->table($viewer, $phalanx)->getEventData($row, $status);
                                $lines[] = $result[1] . '|' . $result[2];
                            }
                        }
                    }
                }
            }
        }

        $this->state->LNG = $previousLng;
        $this->goldenAssert('all event lines', [count($lines), md5(implode("\n", $lines))]);
    }
}
