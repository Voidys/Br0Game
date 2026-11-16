<?php

namespace App\Game\Admin\Creator;

use Symfony\Component\HttpFoundation\Response;

/** Creator tab "testData": creates test players with a planet setup of a chosen game stage. */
class TestDataCreator extends CreatorTab
{
    /** Shows the stage form, or creates the test players and ends with a success message. */
    public function handle(): Response
    {
        $LNG = $this->state->LNG;
        if ($this->state->requestPost) {
            $this->createTestUser();
            $this->responder->message($LNG['test_data_success'], '?page=creator', 3, true);
        }
        $this->responder->assign([
            'gameStages'          => [
                1 => $LNG['test_data_stage'][0],
                2 => $LNG['test_data_stage'][1],
                3 => $LNG['test_data_stage'][2],
                4 => $LNG['test_data_stage'][3],
            ],
        ]);

        return $this->responder->render('admin/CreatePageTestUser.html.twig');
    }

    /**
     * Creates `amount` (POST) test players "TestUser<N>" (account TestUser<N>@test.com, password = name, language of
     * the admin) in the emulated universe. N continues after the highest existing number. Each gets a main planet
     * "TestMainPlanet<N>" plus the research, resources, buildings, ships and defense of the chosen stage
     * (POST `gameStage` 1-4), a moon when the stage has one and the universe has a moon chance, and the stage's number of
     * "TestColony<j>" colonies (with moons as the stage lists them). Writes accounts, users, planets and moons.
     */
    private function createTestUser(): void
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;

        $db = $this->db;
        $amount = $this->http()->_GP('amount', 1);
        $gameStage = $this->http()->_GP('gameStage', 1);

        $universe = $this->universe()->getEmulated();
        $config = $this->configs->get($universe);

        $stageData = $this->getTestGameStageData();

        $sql = "SELECT MAX(
                CAST(
                    SUBSTRING_INDEX(
                        SUBSTRING_INDEX(email, '@', 1),
                        'TestUser',
                        -1
                    ) AS UNSIGNED
                )
            ) AS max_number
            FROM %%ACCOUNTS%%
            WHERE email LIKE 'TestUser%@test.com';";
        $maxNumber = $db->selectSingle($sql, [], 'max_number');

        for ($i = $maxNumber + 1; $i <= $maxNumber + $amount; $i++) {
            $accountId = $this->accountUtil()->createAccount(
                'TestUser' . $i,
                $this->accountUtil()->cryptPassword('TestUser' . $i),
                'TestUser' . $i . '@test.com',
                $USER['lang'],
            );
            $player = $this->playerUtil()->createPlayer(
                $accountId,
                $universe,
                'TestUser' . $i,
                $USER['lang'],
                null,
                null,
                null,
                'TestMainPlanet' . $i,
            );

            $userId = $player[0];
            $planetId = $player[1];
            $this->setTestTechData($userId, $stageData[$gameStage]);
            $this->setTestPlanetData($planetId, $stageData[$gameStage]);

            if ($stageData[$gameStage]['moons'] > 0 && $config->moon_chance > 0) {
                $sql = "SELECT `galaxy`, `system`, `planet` FROM %%PLANETS%% WHERE `id` = :planetId;";
                $planetData = $db->selectSingle($sql, [':planetId' => $planetId]);
                $moonId = $this->playerUtil()->createMoon(
                    $universe,
                    $planetData['galaxy'],
                    $planetData['system'],
                    $planetData['planet'],
                    $userId,
                    $config->moon_chance,
                    'TestMoon',
                );
                $this->setTestMoonData($moonId, $stageData[$gameStage]);
            }

            for ($j = 1; $j <= $stageData[$gameStage]['planets']; $j++) {
                $pos = $this->playerUtil()->getNewPlanetPosition($universe);
                $newPlanetId = $this->playerUtil()->createPlanet(
                    $pos['galaxy'],
                    $pos['system'],
                    $pos['planet'],
                    $universe,
                    $userId,
                    'TestColony' . $j,
                );
                $this->setTestPlanetData($newPlanetId, $stageData[$gameStage]);

                if ($stageData[$gameStage]['moons'] > $j) {
                    $moonId = $this->playerUtil()->createMoon(
                        $universe,
                        $pos['galaxy'],
                        $pos['system'],
                        $pos['planet'],
                        $userId,
                        20,
                        'TestMoon',
                    );
                    $this->setTestMoonData($moonId, $stageData[$gameStage]);
                }
            }
        }
    }

    /** Sets the user's research levels (columns named by the resource ids) to the stage's `research` values; levels are absolute, not added. */
    private function setTestTechData($userId, $stageData): void
    {
        $resource = $this->state->resource;

        $db = $this->db;

        $fields = [];
        $params = [];
        foreach ($stageData['research'] as $ID => $level) {
            $fields[] = "`" . $resource[$ID] . "` = :" . $resource[$ID];
            $params[':' . $resource[$ID]] = $level;
        }
        $params[':userId'] = $userId;

        $sql = "UPDATE %%USERS%% SET " . implode(', ', $fields) . " WHERE id = :userId;";
        $db->update($sql, $params);
    }

    /** Sets a planet's resources, buildings, ships and defense (absolute values, column names from the resource ids) from the stage data. */
    private function setTestPlanetData($planetId, $stageData): void
    {
        $resource = $this->state->resource;

        $fields = [];
        $params = [];
        foreach ($stageData['resources'] as $ID => $amount) {
            $fields[] = "`" . $resource[$ID] . "` = :" . $resource[$ID];
            $params[':' . $resource[$ID]] = $amount;
        }
        foreach ($stageData['buildings'] as $ID => $level) {
            $fields[] = "`" . $resource[$ID] . "` = :" . $resource[$ID];
            $params[':' . $resource[$ID]] = $level;
        }
        foreach ($stageData['fleet'] as $ID => $level) {
            $fields[] = "`" . $resource[$ID] . "` = :" . $resource[$ID];
            $params[':' . $resource[$ID]] = $level;
        }
        foreach ($stageData['defense'] as $ID => $level) {
            $fields[] = "`" . $resource[$ID] . "` = :" . $resource[$ID];
            $params[':' . $resource[$ID]] = $level;
        }
        $params[':planetId'] = $planetId;

        $db = $this->db;

        $sql = "UPDATE %%PLANETS%% SET " . implode(', ', $fields) . " WHERE id = :planetId;";
        $db->update($sql, $params);
    }

    /** Sets a moon's resources and buildings from the stage's `moonResources` and `moonBuildings` (absolute values). */
    private function setTestMoonData($moonId, $stageData): void
    {
        $resource = $this->state->resource;

        $fields = [];
        $params = [];
        foreach ($stageData['moonResources'] as $ID => $amount) {
            $fields[] = "`" . $resource[$ID] . "` = :" . $resource[$ID];
            $params[':' . $resource[$ID]] = $amount;
        }
        foreach ($stageData['moonBuildings'] as $ID => $level) {
            $fields[] = "`" . $resource[$ID] . "` = :" . $resource[$ID];
            $params[':' . $resource[$ID]] = $level;
        }
        $params[':planetId'] = $moonId;

        $db = $this->db;

        $sql = "UPDATE %%PLANETS%% SET " . implode(', ', $fields) . " WHERE id = :planetId;";
        $db->update($sql, $params);
    }

    /**
     * Fixed test setups by stage 1-4 (early, mid, late, end game). Per stage: `planets` (extra colonies) and `moons`
     * counts, `resources`/`moonResources` (units per resource id), and level or count lists `buildings`,
     * `moonBuildings`, `research`, `fleet`, `defense` keyed by element id.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTestGameStageData(): array
    {
        $data = [
            1 => [
                'planets' => 0,
                'moons' => 0,
                'resources' => [
                    RESOURCE_METAL => 20000,
                    RESOURCE_CRYSTAL => 20000,
                    RESOURCE_DEUT => 10000,
                ],
                'moonResources' => [
                    RESOURCE_METAL => 0,
                    RESOURCE_CRYSTAL => 0,
                    RESOURCE_DEUT => 0,
                ],
                'buildings' => [
                    metal_mine => 10,
                    crystal_mine => 9,
                    deuterium_sintetizer => 7,
                    solar_plant => 11,
                    ROBOT_FACTORY => 2,
                    SHIPYARD => 3,
                    METAL_STORE => 2,
                    CRYSTAL_STORE => 1,
                    DEUTERIUM_STORE => 0,
                    RESEARCH_LABORATORY => 3,
                ],
                'moonBuildings' => [
                ],
                'research' => [
                    SPY_TECH => 3,
                    COMPUTER_TECH => 3,
                    ENERGY_TECH => 2,
                    COMBUSTION_TECH => 3,
                ],
                'fleet' => [
                    SHIP_SMALL_CARGO => 5,
                    SHIP_LIGHT_FIGHTER => 5,
                    SHIP_PROBE => 10,
                ],
                'defense' => [
                    MISSILE_LAUNCHER => 2,
                ],
            ],
            2 => [
                'planets' => 5,
                'moons' => 1,
                'resources' => [
                    RESOURCE_METAL => 200000,
                    RESOURCE_CRYSTAL => 150000,
                    RESOURCE_DEUT => 50000,
                ],
                'moonResources' => [
                    RESOURCE_METAL => 0,
                    RESOURCE_CRYSTAL => 0,
                    RESOURCE_DEUT => 5000,
                ],
                'buildings' => [
                    metal_mine => 16,
                    crystal_mine => 15,
                    deuterium_sintetizer => 11,
                    solar_plant => 17,
                    ROBOT_FACTORY => 6,
                    SHIPYARD => 6,
                    METAL_STORE => 5,
                    CRYSTAL_STORE => 4,
                    DEUTERIUM_STORE => 2,
                    RESEARCH_LABORATORY => 7,
                    SILO => 2,
                ],
                'moonBuildings' => [
                    MOONBASE => 4,
                    ROBOT_FACTORY => 5,
                    PHALANX => 2,
                ],
                'research' => [
                    SPY_TECH => 6,
                    COMPUTER_TECH => 8,
                    ENERGY_TECH => 5,
                    HYPERSPACE_TECH => 4,
                    COMBUSTION_TECH => 7,
                    IMPULSE_MOTOR_TECH => 5,
                    HYPERSPACE_MOTOR_TECH => 4,
                    LASER_TECH => 6,
                    EXPEDITION_TECH => 7,
                    METAL_PROC_TECH => 3,
                    CRYSTAL_PROC_TECH => 3,
                    DEUTERIUM_PROC_TECH => 2,
                ],
                'fleet' => [
                    SHIP_SMALL_CARGO => 50,
                    SHIP_LARGE_CARGO => 20,
                    SHIP_LIGHT_FIGHTER => 50,
                    SHIP_CRUISER => 20,
                    SHIP_BATTLESHIP => 2,
                    SHIP_PROBE => 50,
                    SHIP_SOLSAT => 25,
                ],
                'defense' => [
                    MISSILE_LAUNCHER => 40,
                    LIGHT_LASER_TURRET => 20,
                    HEAVY_LASER_TURRET => 10,
                    GAUSS_CANNON => 1,
                    SMALL_SHIELD_DOME => 1,
                    INTERCEPTOR_MISSILE => 5,
                ],
            ],
            3 => [
                'planets' => 7,
                'moons' => 4,
                'resources' => [
                    RESOURCE_METAL => 2000000,
                    RESOURCE_CRYSTAL => 1500000,
                    RESOURCE_DEUT => 500000,
                ],
                'moonResources' => [
                    RESOURCE_METAL => 0,
                    RESOURCE_CRYSTAL => 0,
                    RESOURCE_DEUT => 250000,
                ],
                'buildings' => [
                    metal_mine => 23,
                    crystal_mine => 22,
                    deuterium_sintetizer => 18,
                    solar_plant => 25,
                    ROBOT_FACTORY => 10,
                    SHIPYARD => 8,
                    METAL_STORE => 8,
                    CRYSTAL_STORE => 6,
                    DEUTERIUM_STORE => 5,
                    RESEARCH_LABORATORY => 10,
                    SILO => 4,
                ],
                'moonBuildings' => [
                    MOONBASE => 5,
                    ROBOT_FACTORY => 2,
                    PHALANX => 6,
                ],
                'research' => [
                    SPY_TECH => 8,
                    COMPUTER_TECH => 10,
                    ENERGY_TECH => 8,
                    HYPERSPACE_TECH => 5,
                    COMBUSTION_TECH => 12,
                    IMPULSE_MOTOR_TECH => 8,
                    HYPERSPACE_MOTOR_TECH => 5,
                    LASER_TECH => 12,
                    IONIC_TECH => 5,
                    BUSTER_TECH => 7,
                    INTERGALACTIC_TECH => 1,
                    EXPEDITION_TECH => 11,
                    METAL_PROC_TECH => 6,
                    CRYSTAL_PROC_TECH => 5,
                    DEUTERIUM_PROC_TECH => 4,
                ],
                'fleet' => [
                    SHIP_SMALL_CARGO => 800,
                    SHIP_LARGE_CARGO => 500,
                    SHIP_LIGHT_FIGHTER => 5000,
                    SHIP_CRUISER => 1000,
                    SHIP_BATTLESHIP => 500,
                    SHIP_RECYCLER => 300,
                    SHIP_BATTLECRUISER => 250,
                    SHIP_PROBE => 500,
                    SHIP_SOLSAT => 100,
                ],
                'defense' => [
                    MISSILE_LAUNCHER => 400,
                    LIGHT_LASER_TURRET => 200,
                    HEAVY_LASER_TURRET => 100,
                    GAUSS_CANNON => 20,
                    PLASMA_CANNON => 5,
                    SMALL_SHIELD_DOME => 1,
                    LARGE_SHIELD_DOME => 1,
                    INTERCEPTOR_MISSILE => 20,
                    INTERPLANETARY_MISSILE => 5,
                ],
            ],
            4 => [
                'planets' => 9,
                'moons' => 5,
                'resources' => [
                    RESOURCE_METAL => 20000000,
                    RESOURCE_CRYSTAL => 15000000,
                    RESOURCE_DEUT => 5000000,
                ],
                'moonResources' => [
                    RESOURCE_METAL => 0,
                    RESOURCE_CRYSTAL => 0,
                    RESOURCE_DEUT => 250000,
                ],
                'buildings' => [
                    metal_mine => 30,
                    crystal_mine => 28,
                    deuterium_sintetizer => 25,
                    solar_plant => 32,
                    ROBOT_FACTORY => 10,
                    NANITE_FACTORY => 2,
                    SHIPYARD => 12,
                    METAL_STORE => 10,
                    CRYSTAL_STORE => 8,
                    DEUTERIUM_STORE => 7,
                    RESEARCH_LABORATORY => 10,
                    SILO => 4,
                ],
                'moonBuildings' => [
                    MOONBASE => 5,
                    SPRUNGTOR => 1,
                    PHALANX => 6,
                ],
                'research' => [
                    SPY_TECH => 10,
                    COMPUTER_TECH => 14,
                    ENERGY_TECH => 12,
                    HYPERSPACE_TECH => 8,
                    COMBUSTION_TECH => 14,
                    IMPULSE_MOTOR_TECH => 12,
                    HYPERSPACE_MOTOR_TECH => 9,
                    LASER_TECH => 12,
                    IONIC_TECH => 8,
                    BUSTER_TECH => 8,
                    INTERGALACTIC_TECH => 2,
                    EXPEDITION_TECH => 17,
                    METAL_PROC_TECH => 10,
                    CRYSTAL_PROC_TECH => 8,
                    DEUTERIUM_PROC_TECH => 7,
                    GRAVITON_TECH => 1,
                ],
                'fleet' => [
                    SHIP_SMALL_CARGO => 1800,
                    SHIP_LARGE_CARGO => 1500,
                    SHIP_LIGHT_FIGHTER => 15000,
                    SHIP_CRUISER => 5000,
                    SHIP_BATTLESHIP => 2500,
                    SHIP_RECYCLER => 2300,
                    SHIP_BATTLECRUISER => 1250,
                    SHIP_DESTROYER => 200,
                    SHIP_BOMBER => 100,
                    SHIP_RIP => 5,
                    SHIP_PROBE => 500,
                    SHIP_SOLSAT => 200,
                ],
                'defense' => [
                    MISSILE_LAUNCHER => 1000,
                    LIGHT_LASER_TURRET => 500,
                    HEAVY_LASER_TURRET => 200,
                    GAUSS_CANNON => 100,
                    PLASMA_CANNON => 20,
                    SMALL_SHIELD_DOME => 1,
                    LARGE_SHIELD_DOME => 1,
                    INTERCEPTOR_MISSILE => 20,
                    INTERPLANETARY_MISSILE => 10,
                ],
            ],
        ];
        return $data;
    }
}
