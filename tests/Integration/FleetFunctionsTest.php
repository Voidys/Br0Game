<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\FleetFunctions;
use App\Tests\Support\GameTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Pins the flight mathematics of FleetFunctions: distance between two coordinates (flat and circular universes),
 * ship speed per drive technology, slowest-ship fleet speed, flight duration for every speed setting and game speed,
 * fuel consumption, cargo room, fleet slots, expedition limit, missile and phalanx ranges, missile flight time.
 *
 * Why: fleet travel time and fuel are visible game balance (and are mirrored in the client JS); the FleetFunctions
 * split and the typed models of the legacy renewal must not change a second or a unit of deuterium.
 */
final class FleetFunctionsTest extends GameTestCase
{
    // GOLDEN-BEGIN
    private const GOLDEN = [
        'distance [[1,1,1],[1,1,1],[]]' => 5,
        'distance [[1,100,3],[1,100,11],[]]' => 1040,
        'distance [[2,50,5],[2,51,5],[]]' => 2795,
        'distance [[2,10,5],[2,300,9],[]]' => 30250,
        'distance [[1,10,5],[4,200,9],[]]' => 60000,
        'distance [[1,10,5],[9,10,5],[]]' => 160000,
        'distance [[1,10,5],[9,10,5],{"uni_type":1}]' => 20000,
        'distance [[2,10,5],[7,10,5],{"uni_type":1}]' => 80000,
        'distance [[3,5,5],[3,395,5],{"galaxy_type":1}]' => 3650,
        'distance [[3,100,5],[3,250,5],{"galaxy_type":1}]' => 16950,
        'distance [[3,100,5],[3,250,5],{"galaxy_type":2}]' => 2795,
        'distance [[1,100,5],[8,250,5],{"uni_type":2}]' => 20000,
        'speed [[],202]' => 5000,
        'speed [{"combustion_tech":8},202]' => 9000,
        'speed [{"impulse_motor_tech":6},202]' => 22000,
        'speed [{"hyperspace_motor_tech":9},202]' => 5000,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},202]' => 30000,
        'speed [[],203]' => 7500,
        'speed [{"combustion_tech":8},203]' => 13500,
        'speed [{"impulse_motor_tech":6},203]' => 7500,
        'speed [{"hyperspace_motor_tech":9},203]' => 7500,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},203]' => 15000,
        'speed [[],204]' => 12500,
        'speed [{"combustion_tech":8},204]' => 22500,
        'speed [{"impulse_motor_tech":6},204]' => 12500,
        'speed [{"hyperspace_motor_tech":9},204]' => 12500,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},204]' => 25000,
        'speed [[],205]' => 10000,
        'speed [{"combustion_tech":8},205]' => 10000,
        'speed [{"impulse_motor_tech":6},205]' => 22000,
        'speed [{"hyperspace_motor_tech":9},205]' => 10000,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},205]' => 30000,
        'speed [[],206]' => 15000,
        'speed [{"combustion_tech":8},206]' => 15000,
        'speed [{"impulse_motor_tech":6},206]' => 33000,
        'speed [{"hyperspace_motor_tech":9},206]' => 15000,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},206]' => 45000,
        'speed [[],207]' => 10000,
        'speed [{"combustion_tech":8},207]' => 10000,
        'speed [{"impulse_motor_tech":6},207]' => 10000,
        'speed [{"hyperspace_motor_tech":9},207]' => 37000,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},207]' => 40000,
        'speed [[],208]' => 2500,
        'speed [{"combustion_tech":8},208]' => 2500,
        'speed [{"impulse_motor_tech":6},208]' => 5500,
        'speed [{"hyperspace_motor_tech":9},208]' => 2500,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},208]' => 7500,
        'speed [[],209]' => 2000,
        'speed [{"combustion_tech":8},209]' => 3600,
        'speed [{"impulse_motor_tech":6},209]' => 2000,
        'speed [{"hyperspace_motor_tech":9},209]' => 2000,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},209]' => 4000,
        'speed [[],210]' => 100000000,
        'speed [{"combustion_tech":8},210]' => 180000000,
        'speed [{"impulse_motor_tech":6},210]' => 100000000,
        'speed [{"hyperspace_motor_tech":9},210]' => 100000000,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},210]' => 200000000,
        'speed [[],211]' => 4000,
        'speed [{"combustion_tech":8},211]' => 4000,
        'speed [{"impulse_motor_tech":6},211]' => 8800,
        'speed [{"hyperspace_motor_tech":9},211]' => 18500,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},211]' => 20000,
        'speed [[],213]' => 5000,
        'speed [{"combustion_tech":8},213]' => 5000,
        'speed [{"impulse_motor_tech":6},213]' => 5000,
        'speed [{"hyperspace_motor_tech":9},213]' => 18500,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},213]' => 20000,
        'speed [[],214]' => 100,
        'speed [{"combustion_tech":8},214]' => 100,
        'speed [{"impulse_motor_tech":6},214]' => 100,
        'speed [{"hyperspace_motor_tech":9},214]' => 370,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},214]' => 400,
        'speed [[],215]' => 10000,
        'speed [{"combustion_tech":8},215]' => 10000,
        'speed [{"impulse_motor_tech":6},215]' => 10000,
        'speed [{"hyperspace_motor_tech":9},215]' => 37000,
        'speed [{"combustion_tech":10,"impulse_motor_tech":10,"hyperspace_motor_tech":10},215]' => 40000,
        'fleet speed 202+203+207' => 7500,
        'fleet speed 204+206' => 18750,
        'fleet speed single ship id' => 10000,
        'duration [10,5000,5,1,[]]' => 360,
        'duration [10,5000,1000,1,[]]' => 4959,
        'duration [10,5000,3745,1,[]]' => 9588,
        'duration [10,5000,20000,1,[]]' => 22145,
        'duration [10,5000,100000,1,[]]' => 49507,
        'duration [10,12500,5,1,[]]' => 231,
        'duration [10,12500,1000,1,[]]' => 3140,
        'duration [10,12500,3745,1,[]]' => 6068,
        'duration [10,12500,20000,1,[]]' => 14010,
        'duration [10,12500,100000,1,[]]' => 31314,
        'duration [10,100000,5,1,[]]' => 88,
        'duration [10,100000,1000,1,[]]' => 1116,
        'duration [10,100000,3745,1,[]]' => 2151,
        'duration [10,100000,20000,1,[]]' => 4959,
        'duration [10,100000,100000,1,[]]' => 11077,
        'duration [5,5000,5,1,[]]' => 710,
        'duration [5,5000,1000,1,[]]' => 9909,
        'duration [5,5000,3745,1,[]]' => 19167,
        'duration [5,5000,20000,1,[]]' => 44281,
        'duration [5,5000,100000,1,[]]' => 99004,
        'duration [5,12500,5,1,[]]' => 452,
        'duration [5,12500,1000,1,[]]' => 6270,
        'duration [5,12500,3745,1,[]]' => 12126,
        'duration [5,12500,20000,1,[]]' => 28010,
        'duration [5,12500,100000,1,[]]' => 62619,
        'duration [5,100000,5,1,[]]' => 166,
        'duration [5,100000,1000,1,[]]' => 2223,
        'duration [5,100000,3745,1,[]]' => 4293,
        'duration [5,100000,20000,1,[]]' => 9909,
        'duration [5,100000,100000,1,[]]' => 22145,
        'duration [1,5000,5,1,[]]' => 3510,
        'duration [1,5000,1000,1,[]]' => 49507,
        'duration [1,5000,3745,1,[]]' => 95797,
        'duration [1,5000,20000,1,[]]' => 221369,
        'duration [1,5000,100000,1,[]]' => 494984,
        'duration [1,12500,5,1,[]]' => 2223,
        'duration [1,12500,1000,1,[]]' => 31314,
        'duration [1,12500,3745,1,[]]' => 60591,
        'duration [1,12500,20000,1,[]]' => 140010,
        'duration [1,12500,100000,1,[]]' => 313059,
        'duration [1,100000,5,1,[]]' => 792,
        'duration [1,100000,1000,1,[]]' => 11077,
        'duration [1,100000,3745,1,[]]' => 21428,
        'duration [1,100000,20000,1,[]]' => 49507,
        'duration [1,100000,100000,1,[]]' => 110689,
        'duration [10,12500,3745,2,[]]' => 3034,
        'duration [10,12500,3745,5,[]]' => 1213,
        'duration [10,100000,5,5,[]]' => 17,
        'duration [10,12500,3745,1,{"factor":{"FlyTime":-0.2}}]' => 4854,
        'duration [10,12500,3745,1,{"factor":{"FlyTime":0.5}}]' => 9102,
        'consumption [{"202":10},[],1000,10,1]' => [5000, 4959, 12.0],
        'consumption [{"202":10},[],1000,5,1]' => [5000, 9909, 7.0],
        'consumption [{"203":25},{"combustion_tech":6},3745,10,1]' => [12000, 6193, 536.0],
        'consumption [{"203":5,"205":10,"206":3},{"combustion_tech":8,"impulse_motor_tech":5},8000,10,1]' => [13500, 8530, 1373.0],
        'consumption [{"207":12,"213":4},{"hyperspace_motor_tech":8},20000,10,1]' => [17000, 12014, 19137.0],
        'consumption [{"214":1},{"hyperspace_motor_tech":10},100000,10,1]' => [400, 175010, 12.0],
        'consumption [{"202":10},{"fuel_efficiency_tech":5},1000,10,1]' => [5000, 4959, 11.0],
        'consumption [{"202":10},[],1000,10,3]' => [5000, 1653, 12.0],
        'room 202x10 + 203x5 + 209x2' => 215000,
        'room battleships' => 49500,
        'slots computer tech 7' => 8,
        'expedition limit tech 10' => 3,
        'missile range {"ion":4,"from":[1,100],"to":[1,110],"config":[]}' => true,
        'missile range {"ion":4,"from":[1,100],"to":[1,119],"config":[]}' => true,
        'missile range {"ion":4,"from":[1,100],"to":[1,120],"config":[]}' => false,
        'missile range {"ion":10,"from":[1,100],"to":[2,100],"config":[]}' => false,
        'missile range {"ion":4,"from":[1,395],"to":[1,8],"config":{"galaxy_type":1}}' => true,
        'missile range {"ion":4,"from":[1,395],"to":[1,20],"config":{"galaxy_type":1}}' => false,
        'missile range {"ion":1,"from":[1,100],"to":[1,300],"config":{"galaxy_type":2}}' => true,
        'missile range {"ion":3,"from":[1,100],"to":[1,300],"config":{"galaxy_type":2}}' => true,
        'mip duration 0 systems' => 30,
        'mip duration 1 systems' => 90,
        'mip duration 7 systems' => 450,
        'mip duration 150 systems' => 9030,
        'mip duration 399 systems' => 23970,
        'mip duration circular 5 -> 395' => 630,
        'mip duration galaxy_type 2' => 150,
        'phalanx range 1' => 0,
        'phalanx range 2' => 3.0,
        'phalanx range 5' => 24.0,
        'phalanx range 10' => 99.0,
    ];
    // GOLDEN-END

    private FleetFunctions $fleets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fleets = new FleetFunctions($this->db, $this->configs, $this->state);
    }

    /** @return iterable<string, array{array<int>, array<int>, array<string, mixed>}> */
    public static function distanceCases(): iterable
    {
        yield 'same planet' => [[1, 1, 1], [1, 1, 1], []];
        yield 'same system, planets 3 -> 11' => [[1, 100, 3], [1, 100, 11], []];
        yield 'neighbour systems' => [[2, 50, 5], [2, 51, 5], []];
        yield 'systems 10 -> 300' => [[2, 10, 5], [2, 300, 9], []];
        yield 'galaxies 1 -> 4' => [[1, 10, 5], [4, 200, 9], []];
        yield 'galaxies 1 -> 9' => [[1, 10, 5], [9, 10, 5], []];
        yield 'circular galaxies 1 -> 9 (uni_type 1)' => [[1, 10, 5], [9, 10, 5], ['uni_type' => 1]];
        yield 'circular galaxies 2 -> 7 (uni_type 1)' => [[2, 10, 5], [7, 10, 5], ['uni_type' => 1]];
        yield 'circular systems 5 -> 395 (galaxy_type 1)' => [[3, 5, 5], [3, 395, 5], ['galaxy_type' => 1]];
        yield 'circular systems 100 -> 250 (galaxy_type 1)' => [[3, 100, 5], [3, 250, 5], ['galaxy_type' => 1]];
        yield 'galaxy_type 2 (all systems equal)' => [[3, 100, 5], [3, 250, 5], ['galaxy_type' => 2]];
        yield 'uni_type 2 (all galaxies equal)' => [[1, 100, 5], [8, 250, 5], ['uni_type' => 2]];
    }

    /**
     * @param array<int> $start
     * @param array<int> $target
     * @param array<string, mixed> $config
     */
    #[DataProvider('distanceCases')]
    public function testTargetDistance(array $start, array $target, array $config): void
    {
        $this->setUniverseConfig($config);

        $this->goldenAssert('distance ' . json_encode([$start, $target, $config]), $this->fleets->getTargetDistance($start, $target));
    }

    /** @return iterable<string, array{array<string, mixed>, int}> */
    public static function speedCases(): iterable
    {
        foreach ([202, 203, 204, 205, 206, 207, 208, 209, 210, 211, 213, 214, 215] as $ship) {
            yield "ship $ship, no drive tech" => [[], $ship];
            yield "ship $ship, combustion 8" => [['combustion_tech' => 8], $ship];
            yield "ship $ship, impulse 6" => [['impulse_motor_tech' => 6], $ship];
            yield "ship $ship, hyperspace drive 9" => [['hyperspace_motor_tech' => 9], $ship];
            yield "ship $ship, all drives 10/10/10" => [['combustion_tech' => 10, 'impulse_motor_tech' => 10, 'hyperspace_motor_tech' => 10], $ship];
        }
    }

    /** @param array<string, mixed> $tech */
    #[DataProvider('speedCases')]
    public function testShipSpeedUsesTheBestUnlockedDrive(array $tech, int $ship): void
    {
        [$user] = $this->createPlayer($tech);

        $this->goldenAssert('speed ' . json_encode([$tech, $ship]), $this->fleets->getFleetMaxSpeed([$ship => 1], $user));
    }

    public function testFleetSpeedIsTheSlowestShip(): void
    {
        [$user] = $this->createPlayer(['combustion_tech' => 5, 'impulse_motor_tech' => 3]);

        $this->goldenAssert('fleet speed 202+203+207', $this->fleets->getFleetMaxSpeed([202 => 100, 203 => 10, 207 => 3], $user));
        $this->goldenAssert('fleet speed 204+206', $this->fleets->getFleetMaxSpeed([204 => 5, 206 => 2], $user));
        $this->goldenAssert('fleet speed single ship id', $this->fleets->getFleetMaxSpeed(207, $user));
        $this->assertSame(0, $this->fleets->getFleetMaxSpeed([], $user));
    }

    /** @return iterable<string, array{int, int, int, int, array<string, mixed>}> speedFactor, maxSpeed, distance, gameSpeed, user */
    public static function durationCases(): iterable
    {
        foreach ([10, 5, 1] as $factor) {
            foreach ([5000, 12500, 100000] as $speed) {
                foreach ([5, 1000, 3745, 20000, 100000] as $distance) {
                    yield "factor $factor speed $speed distance $distance" => [$factor, $speed, $distance, 1, []];
                }
            }
        }
        yield 'game speed 2' => [10, 12500, 3745, 2, []];
        yield 'game speed 5' => [10, 12500, 3745, 5, []];
        yield 'game speed 5, very short flight (minimum duration)' => [10, 100000, 5, 5, []];
        yield 'fly time bonus -20%' => [10, 12500, 3745, 1, ['factor' => ['FlyTime' => -0.2]]];
        yield 'fly time malus +50%' => [10, 12500, 3745, 1, ['factor' => ['FlyTime' => 0.5]]];
    }

    /** @param array<string, mixed> $user */
    #[DataProvider('durationCases')]
    public function testMissionDuration(int $factor, int $speed, int $distance, int $gameSpeed, array $user): void
    {
        $this->goldenAssert('duration ' . json_encode(func_get_args()), $this->fleets->getMissionDuration($factor, $speed, $distance, $gameSpeed, $user));
    }

    /** @return iterable<string, array{array<int, int>, array<string, mixed>, int, int, int}> */
    public static function consumptionCases(): iterable
    {
        yield 'small cargo x10, 1000 distance' => [[202 => 10], [], 1000, 10, 1];
        yield 'small cargo x10, 1000 distance, slow' => [[202 => 10], [], 1000, 5, 1];
        yield 'large cargo x25, 3745 distance' => [[203 => 25], ['combustion_tech' => 6], 3745, 10, 1];
        yield 'mixed fleet, impulse drive' => [[203 => 5, 205 => 10, 206 => 3], ['combustion_tech' => 8, 'impulse_motor_tech' => 5], 8000, 10, 1];
        yield 'battleships, hyperspace drive' => [[207 => 12, 213 => 4], ['hyperspace_motor_tech' => 8], 20000, 10, 1];
        yield 'death star, long flight' => [[214 => 1], ['hyperspace_motor_tech' => 10], 100000, 10, 1];
        yield 'fuel efficiency 5' => [[202 => 10], ['fuel_efficiency_tech' => 5], 1000, 10, 1];
        yield 'game speed 3' => [[202 => 10], [], 1000, 10, 3];
    }

    /**
     * @param array<int, int> $fleet
     * @param array<string, mixed> $tech
     */
    #[DataProvider('consumptionCases')]
    public function testFleetConsumption(array $fleet, array $tech, int $distance, int $speedFactor, int $gameSpeed): void
    {
        [$user] = $this->createPlayer($tech);
        $maxSpeed = $this->fleets->getFleetMaxSpeed($fleet, $user);
        $duration = $this->fleets->getMissionDuration($speedFactor, $maxSpeed, $distance, $gameSpeed, $user);

        $this->goldenAssert('consumption ' . json_encode(func_get_args()), [$maxSpeed, $duration, $this->fleets->getFleetConsumption($fleet, $duration, $distance, $user, $gameSpeed)]);
    }

    public function testFleetRoomAndSlots(): void
    {
        [$user] = $this->createPlayer(['computer_tech' => 7, 'expedition_tech' => 10]);

        $this->goldenAssert('room 202x10 + 203x5 + 209x2', $this->fleets->getFleetRoom([202 => 10, 203 => 5, 209 => 2]));
        $this->goldenAssert('room battleships', $this->fleets->getFleetRoom([207 => 33]));
        $this->goldenAssert('slots computer tech 7', $this->fleets->getMaxFleetSlots($user));
        $this->goldenAssert('expedition limit tech 10', $this->fleets->getExpeditionLimit($user));
        $this->assertSame(0, $this->fleets->getFleetRoom([]));
    }

    public function testGameSpeedFactorFollowsTheFleetSpeedSetting(): void
    {
        $this->assertSame(1, $this->fleets->getGameSpeedFactor());
        $this->setUniverseConfig(['fleet_speed' => 12500]);
        $this->assertSame(5, $this->fleets->getGameSpeedFactor());
    }

    public function testAllowedSpeedSettings(): void
    {
        foreach (range(1, 10) as $speed) {
            $this->assertTrue($this->fleets->checkUserSpeed($speed));
        }
        foreach ([0, 11, -1] as $speed) {
            $this->assertFalse($this->fleets->checkUserSpeed($speed));
        }
        $this->assertSame([10 => 100, 9 => 90, 8 => 80, 7 => 70, 6 => 60, 5 => 50, 4 => 40, 3 => 30, 2 => 20, 1 => 10], FleetFunctions::$allowedSpeed);
    }

    /** @return iterable<string, array{array<string, mixed>}> */
    public static function missileCases(): iterable
    {
        yield 'flat, ion 4, 10 systems away' => [['ion' => 4, 'from' => [1, 100], 'to' => [1, 110], 'config' => []]];
        yield 'flat, ion 4, 19 systems away' => [['ion' => 4, 'from' => [1, 100], 'to' => [1, 119], 'config' => []]];
        yield 'flat, ion 4, 20 systems away' => [['ion' => 4, 'from' => [1, 100], 'to' => [1, 120], 'config' => []]];
        yield 'flat, other galaxy' => [['ion' => 10, 'from' => [1, 100], 'to' => [2, 100], 'config' => []]];
        yield 'circular, wrapping range' => [['ion' => 4, 'from' => [1, 395], 'to' => [1, 8], 'config' => ['galaxy_type' => 1]]];
        yield 'circular, outside range' => [['ion' => 4, 'from' => [1, 395], 'to' => [1, 20], 'config' => ['galaxy_type' => 1]]];
        yield 'galaxy_type 2, ion 1' => [['ion' => 1, 'from' => [1, 100], 'to' => [1, 300], 'config' => ['galaxy_type' => 2]]];
        yield 'galaxy_type 2, ion 3' => [['ion' => 3, 'from' => [1, 100], 'to' => [1, 300], 'config' => ['galaxy_type' => 2]]];
    }

    /** @param array{ion: int, from: array<int>, to: array<int>, config: array<string, mixed>} $case */
    #[DataProvider('missileCases')]
    public function testMissileRange(array $case): void
    {
        $this->setUniverseConfig($case['config']);
        $from = ['galaxy' => $case['from'][0], 'system' => $case['from'][1]];
        $to = ['galaxy' => $case['to'][0], 'system' => $case['to'][1]];

        $this->goldenAssert('missile range ' . json_encode($case), $this->fleets->inMissileRange($from, $to, $case['ion']));
    }

    public function testMissileFlightTimeAndPhalanxRange(): void
    {
        foreach ([0, 1, 7, 150, 399] as $distance) {
            $this->goldenAssert("mip duration $distance systems", $this->fleets->getMIPDuration(100, 100 + $distance));
        }
        $this->setUniverseConfig(['galaxy_type' => 1]);
        $this->goldenAssert('mip duration circular 5 -> 395', $this->fleets->getMIPDuration(5, 395));
        $this->setUniverseConfig(['galaxy_type' => 2]);
        $this->goldenAssert('mip duration galaxy_type 2', $this->fleets->getMIPDuration(5, 395));
        foreach ([1, 2, 5, 10] as $level) {
            $this->goldenAssert("phalanx range $level", $this->fleets->getPhalanxRange($level));
        }
    }
}
