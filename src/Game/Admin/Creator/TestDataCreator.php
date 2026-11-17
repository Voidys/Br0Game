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
            $this->createTestPlayer($i, $universe, $config, $stageData[$gameStage]);
        }
    }

    /**
     * Creates test player "TestUser<number>" with its account, main planet, optional moon and colonies.
     *
     * @param int $number running test user number
     * @param int $universe emulated universe
     * @param \App\Game\Core\Config $config universe config (moon chance)
     * @param array<string, mixed> $stage the chosen stage's data (see TestGameStages)
     */
    private function createTestPlayer(int $number, int $universe, $config, array $stage): void
    {
        $lang = $this->state->USER['lang'];
        $accountId = $this->accountUtil()->createAccount(
            'TestUser' . $number,
            $this->accountUtil()->cryptPassword('TestUser' . $number),
            'TestUser' . $number . '-u' . $universe . '@test.com',
            $lang,
        );
        $player = $this->playerUtil()->createPlayer(
            $accountId,
            $universe,
            'TestUser' . $number,
            $lang,
            null,
            null,
            null,
            'TestMainPlanet' . $number,
        );

        $userId = $player[0];
        $planetId = $player[1];
        $this->setTestTechData($userId, $stage);
        $this->setTestPlanetData($planetId, $stage);

        if ($stage['moons'] > 0 && $config->moon_chance > 0) {
            $sql = "SELECT `galaxy`, `system`, `planet` FROM %%PLANETS%% WHERE `id` = :planetId;";
            $planetData = $this->db->selectSingle($sql, [':planetId' => $planetId]);
            $moonId = $this->playerUtil()->createMoon(
                $universe,
                $planetData['galaxy'],
                $planetData['system'],
                $planetData['planet'],
                $userId,
                $config->moon_chance,
                'TestMoon',
            );
            $this->setTestMoonData($moonId, $stage);
        }

        $this->createTestColonies($userId, $universe, $stage);
    }

    /**
     * Creates the stage's colonies "TestColony<j>" at new positions; colony j gets a moon while the stage lists more
     * moons than j (moon chance fixed at 20).
     *
     * @param array<string, mixed> $stage the chosen stage's data (see TestGameStages)
     */
    private function createTestColonies(int $userId, int $universe, array $stage): void
    {
        for ($j = 1; $j <= $stage['planets']; $j++) {
            $pos = $this->playerUtil()->getNewPlanetPosition($universe);
            $newPlanetId = $this->playerUtil()->createPlanet(
                $pos['galaxy'],
                $pos['system'],
                $pos['planet'],
                $universe,
                $userId,
                'TestColony' . $j,
            );
            $this->setTestPlanetData($newPlanetId, $stage);

            if ($stage['moons'] > $j) {
                $moonId = $this->playerUtil()->createMoon(
                    $universe,
                    $pos['galaxy'],
                    $pos['system'],
                    $pos['planet'],
                    $userId,
                    20,
                    'TestMoon',
                );
                $this->setTestMoonData($moonId, $stage);
            }
        }
    }

    /** Sets the user's research levels (columns named by the resource ids) to the stage's `research` values; levels are absolute, not added. */
    private function setTestTechData($userId, $stageData): void
    {
        $this->updateAbsoluteValues('USERS', $userId, [$stageData['research']]);
    }

    /** Sets a planet's resources, buildings, ships and defense (absolute values, column names from the resource ids) from the stage data. */
    private function setTestPlanetData($planetId, $stageData): void
    {
        $this->updateAbsoluteValues('PLANETS', $planetId, [
            $stageData['resources'],
            $stageData['buildings'],
            $stageData['fleet'],
            $stageData['defense'],
        ]);
    }

    /** Sets a moon's resources and buildings from the stage's `moonResources` and `moonBuildings` (absolute values). */
    private function setTestMoonData($moonId, $stageData): void
    {
        $this->updateAbsoluteValues('PLANETS', $moonId, [$stageData['moonResources'], $stageData['moonBuildings']]);
    }

    /**
     * UPDATEs one row of the table, setting every listed element column (named by its resource id) to the absolute value.
     *
     * @param string $table game table key: USERS or PLANETS
     * @param int $rowId user or planet id
     * @param array<int, array<int, int|float>> $valueLists element id => value lists, applied in order
     */
    private function updateAbsoluteValues(string $table, $rowId, array $valueLists): void
    {
        $e = $this->elements();

        // column names come from the game's resource table; a later list overwrites an earlier value of the same column
        $columns = [];
        foreach ($valueLists as $values) {
            foreach ($values as $ID => $value) {
                $columns[$e->column($ID)] = $value;
            }
        }

        $this->db->updateRows($table, $columns, ['id' => $rowId]);
    }

    /**
     * Fixed test setups by stage 1-4 (early, mid, late, end game), see TestGameStages for the layout.
     *
     * @return array<int, array<string, mixed>>
     */
    private function getTestGameStageData(): array
    {
        return TestGameStages::STAGES;
    }
}
