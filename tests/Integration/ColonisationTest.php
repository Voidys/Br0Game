<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Database;
use App\Game\Core\FleetFunctions;
use App\Game\Missions\MissionCaseColonisation;
use App\Tests\Support\GameTestCase;
use App\Game\Enum\Mission;

/**
 * Colonisation arrivals: a taken position is refused, and the planet position is unique in the database, so racing
 * colonisations can never create two planets at one position.
 */
final class ColonisationTest extends GameTestCase
{
    private const TARGET = [4, 321, 9];

    /**
     * @param array<string, mixed> $planet
     * @param array<string, mixed> $user
     * @return array<string, mixed> fleet row of a colony ship that arrived at the target position
     */
    private function colonyFleet(array $user, array $planet): array
    {
        $now = time();
        $this->state->USER = $user;
        $this->state->PLANET = $planet;
        [$galaxy, $system, $slot] = self::TARGET;
        $id = (new FleetFunctions($this->db, $this->configs, $this->state))->sendFleet(
            [208 => 1],
            Mission::Colonisation->value,
            (int) $user['id'],
            (int) $planet['id'],
            (int) $planet['galaxy'],
            (int) $planet['system'],
            (int) $planet['planet'],
            1,
            0,
            0,
            $galaxy,
            $system,
            $slot,
            1,
            [901 => 0, 902 => 0, 903 => 0],
            $now - 100,
            $now - 100,
            $now + 5000,
        );

        return $this->db->selectSingle('SELECT * FROM %%FLEETS%% WHERE id = :id;', [':id' => $id]);
    }

    private function planetsAtTarget(): int
    {
        [$galaxy, $system, $slot] = self::TARGET;

        return (int) $this->db->selectSingle(
            'SELECT COUNT(*) AS c FROM %%PLANETS%% WHERE universe = 1 AND galaxy = :g AND `system` = :s AND planet = :p AND planet_type = 1;',
            [':g' => $galaxy, ':s' => $system, ':p' => $slot],
            'c',
        );
    }

    public function testASecondColonisationAtATakenPositionIsRefused(): void
    {
        $this->setUniverseConfig(['max_initial_planets' => 9]);
        $resource = $this->elements()->columnMap();
        [$a, $aPlanet] = $this->createPlayer([], [$resource[208] => 5, 'deuterium' => 1e6]);
        [$b, $bPlanet] = $this->createPlayer([], [$resource[208] => 5, 'deuterium' => 1e6]);
        $first = $this->colonyFleet($a, $aPlanet);
        $second = $this->colonyFleet($b, $bPlanet);

        (new MissionCaseColonisation($first, $this->db, $this->configs, $this->state))->TargetEvent();
        (new MissionCaseColonisation($second, $this->db, $this->configs, $this->state))->TargetEvent();

        $this->assertSame(1, $this->planetsAtTarget());
        $owner = (int) $this->db->selectSingle('SELECT id_owner FROM %%PLANETS%% WHERE universe = 1 AND galaxy = 4 AND `system` = 321 AND planet = 9;', [], 'id_owner');
        $this->assertSame((int) $a['id'], $owner);
    }

    public function testThePlanetLimitIsEnforced(): void
    {
        $this->setUniverseConfig(['max_initial_planets' => 1]);
        $resource = $this->elements()->columnMap();
        [$user, $planet] = $this->createPlayer([], [$resource[208] => 5, 'deuterium' => 1e6]);

        (new MissionCaseColonisation($this->colonyFleet($user, $planet), $this->db, $this->configs, $this->state))->TargetEvent();

        $this->assertSame(0, $this->planetsAtTarget());
    }

    public function testThePlanetPositionIsUniqueInTheDatabase(): void
    {
        [, $planet] = $this->createPlayer();

        try {
            $this->db->insert(
                'INSERT INTO %%PLANETS%% SET id_owner = :o, universe = 1, galaxy = :g, `system` = :s, planet = :p, planet_type = 1, name = :n;',
                [':o' => $planet['id_owner'], ':g' => $planet['galaxy'], ':s' => $planet['system'], ':p' => $planet['planet'], ':n' => 'dup'],
            );
            $this->fail('a second planet at the same position was accepted');
        } catch (\Throwable $exception) {
            $this->assertSame(Database::ERROR_DUPLICATE_KEY, Database::errorNumber($exception));
        }
    }
}
