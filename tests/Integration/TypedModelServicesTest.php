<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\BuildFunctions;
use App\Game\Core\FleetMath;
use App\Game\Core\PlayerUtil;
use App\Game\Core\ResourceProduction;
use App\Game\Model\ModelFactory;
use App\Game\Model\Planet;
use App\Game\Model\User;
use App\Tests\Support\GameTestCase;

/**
 * Parity of the typed service methods (User/Planet models) with the array signatures that remain as adapters:
 * BuildFunctions (price, rest price, buyable, requirements, time, max constructible, rockets), ResourceProduction
 * (eco hash, rebuild, research network), FleetMath (slots, expedition limit, speed, duration, consumption) and the
 * protection rules. The eco hash is additionally compared with the legacy array formula, because the stored
 * `eco_hash` values must stay valid.
 */
final class TypedModelServicesTest extends GameTestCase
{
    private BuildFunctions $build;
    private ModelFactory $models;

    protected function setUp(): void
    {
        parent::setUp();
        $this->build = new BuildFunctions($this->db, $this->configs, $this->state);
        $this->models = new ModelFactory($this->state);
    }

    /** @return array{0: array<string, mixed>, 1: array<string, mixed>, 2: User, 3: Planet} */
    private function player(): array
    {
        [$user, $planet] = $this->createPlayer(
            ['computer_tech' => 4, 'energy_tech' => 3, 'combustion_tech' => 6, 'impulse_motor_tech' => 4, 'hyperspace_motor_tech' => 2, 'fuel_efficiency_tech' => 3, 'expedition_tech' => 9, 'intergalactic_tech' => 1, 'spy_tech' => 5],
            ['metal' => 5000.7, 'crystal' => 3000, 'deuterium' => 900, 'robot_factory' => 4, 'nano_factory' => 1, 'hangar' => 6, 'laboratory' => 7, 'silo' => 3, 'metal_mine' => 10, 'solar_plant' => 8, 'light_hunter' => 12, 'small_ship_cargo' => 40, 'interceptor_misil' => 2],
        );

        return [$user, $planet, $this->models->user($user), $this->models->planet($planet)];
    }

    public function testBuildFunctionsTypedMatchesArrays(): void
    {
        [$user, $planet, $userModel, $planetModel] = $this->player();
        $planet['laboratory_inter'] = [7, 5, 3];
        $planetModel = $this->models->planet($planet);

        foreach ([1, 2, 4, 14, 15, 31, 44, 106, 108, 120, 202, 204, 207, 401, 502] as $element) {
            $this->assertSame($this->build->getElementPrice($user, $planet, $element), $this->build->elementPriceFor($userModel, $planetModel, $element), "price $element");
            $this->assertSame($this->build->getElementPrice($user, $planet, $element, true, 5), $this->build->elementPriceFor($userModel, $planetModel, $element, true, 5), "destroy price $element");
            $this->assertSame($this->build->getRestPrice($user, $planet, $element), $this->build->restPriceFor($userModel, $planetModel, $element), "rest $element");
            $this->assertSame($this->build->isElementBuyable($user, $planet, $element), $this->build->isBuyableFor($userModel, $planetModel, $element), "buyable $element");
            $this->assertSame($this->build->isTechnologieAccessible($user, $planet, $element), $this->build->isAccessibleFor($userModel, $planetModel, $element), "access $element");
            $this->assertSame($this->build->getBuildingTime($user, $planet, $element), $this->build->buildingTimeFor($userModel, $planetModel, $element), "time $element");
            $this->assertSame($this->build->getMaxConstructibleElements($user, $planet, $element), $this->build->maxConstructibleFor($userModel, $planetModel, $element), "max $element");
        }
        $this->assertSame($this->build->getMaxConstructibleRockets($planet), $this->build->maxRocketsFor($planetModel));
    }

    public function testRequirementsLookAtUserTechsAndPlanetBuildings(): void
    {
        [, , $userModel, $planetModel] = $this->player();

        // rocket silo needs the shipyard (planet) at level 1; expedition-like techs need the lab and other techs
        $this->assertTrue($this->build->isAccessibleFor($userModel, $planetModel, 44));
        $empty = $this->models->planet(['id' => 1, 'hangar' => 0]);
        $this->assertFalse($this->build->isAccessibleFor($userModel, $empty, 44) && $this->build->isAccessibleFor($userModel, $empty, 207));
    }

    public function testResourceProductionTypedMatchesArrays(): void
    {
        [$user, $planet, $userModel, $planetModel] = $this->player();
        $production = new ResourceProduction($this->db, $this->configs, $this->state);
        $config = $this->configs->get(1);
        $resource = $this->state->resource;
        $reslist = $this->state->reslist;

        $this->assertSame($production->createHash($user, $planet, $config), $production->hashFor($userModel, $planetModel, $config));

        // the stored eco_hash values were made from the raw array values: keep the string identical
        $hash = [];
        foreach ($reslist['prod'] as $id) {
            $hash[] = $planet[$resource[$id]];
            $hash[] = $planet[$resource[$id] . '_porcent'];
        }
        foreach (array_merge([], $reslist['resstype'][1], $reslist['resstype'][2]) as $id) {
            $hash[] = $config->{$resource[$id] . '_basic_income'};
        }
        array_push($hash, $config->resource_multiplier, $config->storage_multiplier, $config->energy_multiplier);
        foreach ([22, 23, 24] as $id) {
            $hash[] = $planet[$resource[$id]];
        }
        foreach ([113, 131, 132, 133] as $id) {
            $hash[] = $user[$resource[$id]];
        }
        $this->assertSame(md5(implode('::', $hash)), $production->hashFor($userModel, $planetModel, $config));

        $rebuilt = $production->rebuild($user, $planet, $config);
        $typed = $production->rebuildFor($userModel, $planetModel, $config);
        $this->assertSame(array_replace($planet, $typed), $rebuilt);
        foreach (['metal_max', 'crystal_max', 'deuterium_max', 'energy', 'energy_used', 'metal_perhour', 'crystal_perhour', 'deuterium_perhour'] as $key) {
            $this->assertArrayHasKey($key, $typed);
        }

        $this->assertSame($production->getNetworkLevel($user, $planet), $production->networkLevelFor($userModel, $planetModel));
    }

    public function testFleetMathTypedMatchesArrays(): void
    {
        [$user, , $userModel] = $this->player();
        $math = new FleetMath($this->db, $this->configs, $this->state);
        $fleet = [202 => 5, 204 => 3, 207 => 2];

        $this->assertSame($math->getMaxFleetSlots($user), $math->maxFleetSlotsFor($userModel));
        $this->assertSame(5, $math->maxFleetSlotsFor($userModel));
        $this->assertSame($math->getExpeditionLimit($user), $math->expeditionLimitFor($userModel));
        $this->assertSame(3, $math->expeditionLimitFor($userModel));
        $this->assertSame($math->getFleetMaxSpeed($fleet, $user), $math->fleetMaxSpeedFor($fleet, $userModel));
        $this->assertSame($math->getFleetMaxSpeed(202, $user), $math->fleetMaxSpeedFor(202, $userModel));
        $this->assertSame($math->getFleetMaxSpeed([], $user), $math->fleetMaxSpeedFor([], $userModel));
        $this->assertSame($math->getFleetConsumption($fleet, 3600, 40, $user, 1), $math->fleetConsumptionFor($fleet, 3600, 40, $userModel, 1));
        $this->assertSame($math->getFleetShipInfo($fleet, $user), $math->fleetShipInfoFor($fleet, $userModel));

        $speed = $math->fleetMaxSpeedFor($fleet, $userModel);
        $this->assertSame($math->getMissionDuration(10, $speed, 500, 1, $user), $math->missionDurationFor(10, $speed, 500, 1, $userModel));

        // the FlyTime bonus factor shortens the flight
        $fast = $user + ['factor' => ['FlyTime' => -0.5]];
        $this->assertSame($math->getMissionDuration(10, $speed, 500, 1, $fast), $math->missionDurationFor(10, $speed, 500, 1, $this->models->user($fast)));
        $this->assertLessThan($math->missionDurationFor(10, $speed, 500, 1, $userModel), $math->missionDurationFor(10, $speed, 500, 1, $this->models->user($fast)));
    }

    public function testProtectionRulesTypedMatchesArrays(): void
    {
        [$user, , $userModel] = $this->player();
        [$other] = $this->createPlayer(['onlinetime' => time()], []);
        $players = new PlayerUtil($this->db, $this->configs, $this->state);

        $this->assertSame($players->getCatchUpFactor($user), $players->catchUpFactorFor($userModel));

        $user['total_points'] = 100000;
        $other['total_points'] = 50;
        $this->setUniverseConfig(['noob_protection' => 1, 'noob_protection_time' => 5000, 'noob_protection_multi' => 5]);
        $this->assertSame(
            $players->checkNoobProtection($user, $other),
            $players->checkNoobProtectionFor($this->models->user($user), $this->models->user($other)),
        );
        $this->assertTrue($players->checkNoobProtectionFor($this->models->user($user), $this->models->user($other))['NoobPlayer']);
        $this->assertTrue($players->checkNoobProtectionFor($this->models->user($other), $this->models->user($user))['StrongPlayer']);

        $this->assertSame($players->spyTechAllowed(4, $other['id'], $user), $players->spyTechAllowedFor(4, (int) $other['id'], $userModel));
    }
}
