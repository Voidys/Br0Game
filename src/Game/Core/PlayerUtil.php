<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Core;

use Exception;
use App\Game\Achievements\PlayerUtilAchievement;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;

class PlayerUtil
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    public function isPositionFree(int $universe, int $galaxy, int $system, int $planet, string $type = TYPE_PLANET): bool
    {
        $db = $this->db;
        $sql = 'SELECT COUNT(*) as record
            FROM %%PLANETS%%
            WHERE `universe` = :universe
            AND `galaxy` = :galaxy
            AND `system` = :system
            AND `planet` = :planet
            AND `planet_type` = :type;';

        $count = $db->selectSingle($sql, [
            ':universe' => $universe,
            ':galaxy'   => $galaxy,
            ':system'   => $system,
            ':planet' => $planet,
            ':type'     => $type,
        ], 'record');

        return $count == 0 && $planet > 0 && $system > 0 && $galaxy > 0;
    }

    public function isNameValid(string $name, bool $isPlanetName = false): bool
    {
        if (UTF8_SUPPORT && $isPlanetName) {
            return preg_match('/^[\p{L}\p{N}_\-. ]*$/u', $name);
        } elseif (UTF8_SUPPORT) {
            return preg_match('/^[0-9a-zA-ZäöüÄÖÜß _.-]*$/u', $name);
        } else {
            return preg_match('/^[A-z0-9_\-. ]*$/', $name);
        }
    }

    public function checkPosition(int $universe, int $galaxy, int $system, int $planet): bool
    {
        $config = $this->configs->get($universe);

        return !(1 > $galaxy
            || 1 > $system
            || 1 > $planet
            || $config->max_galaxy < $galaxy
            || $config->max_system < $system
            || $config->max_planets < $planet);
    }

    public function getPlanetDataPlanetConfig(): array
    {
        $db = $this->db;
        $planetDataBackend = [];

        $sql = "SELECT * FROM %%PLANET_DATA%% WHERE universe = :universe";
        $planetDataBackend = $db->select($sql, [
            ':universe' => 0,
        ]);

        $sql = "SELECT * FROM %%PLANET_DATA%% WHERE universe = :universe";
        $planetDataBackend_universe = $db->select($sql, [
            ':universe' => $this->universe()->current(),
        ]);

        // Step 1: Re-index generic array by `planet_position`
        $genericByPosition = [];
        foreach ($planetDataBackend as $entry) {
            $genericByPosition[$entry['planet_position']] = $entry;
        }

        // Step 2: Replace entries in the indexed array with specific entries based on `planet_position`
        foreach ($planetDataBackend_universe as $specificEntry) {
            $positionSorted = $specificEntry['planet_position'];
            // If the position exists in the generic array, replace it
            $genericByPosition[$positionSorted] = $specificEntry;
        }

        // Step 3: Re-index the array by resetting the keys to a numeric index if needed
        $planetDataBackend = array_values($genericByPosition);

        return $planetDataBackend;
    }

    public function randomHP(int $universe): array
    {
        $config = $this->configs->get($universe);
        $db = $this->db;
        //get avg planets per system per galaxy
        $sql = 'SELECT tab.`galaxy`, (sum(tab.anz)/:maxSys) as AvgPlanetsPerSys FROM (
            SELECT p.`galaxy`, p.`system`, COUNT(p.`planet`) as anz
            FROM %%PLANETS%% p
            JOIN %%USERS%% u on u.`id` = p.`id_owner`
            WHERE `planet_type` = 1 AND p.`galaxy` <= :maxGala AND u.`onlinetime` >= ( :ts - :inactive ) AND p.`universe` = :universe
            GROUP BY p.`galaxy`, p.`system`
        ) as tab GROUP BY `galaxy` ORDER BY tab.`galaxy` ASC;';
        $result = $db->select($sql, [
            ':universe' => $universe,
            ':maxGala' => $config->max_galaxy,
            ':maxSys' => $config->max_system,
            ':ts' => $this->state->runtime->timestamp,
            ':inactive' => INACTIVE,
        ]);

        $avgPlanetsPerGala = [];
        if (empty($result)) {
            for ($i = 1; $i <= $config->max_galaxy; $i++) {
                $avgPlanetsPerGala[] = ['galaxy' => $i, 'AvgPlanetsPerSys' => 0];
            }
        } else {
            $i = 1;
            foreach ($result as $resultArray) {
                while ($i < $resultArray['galaxy']) {
                    $avgPlanetsPerGala[] = ['galaxy' => $i, 'AvgPlanetsPerSys' => 0];
                    $i++;
                }
                $avgPlanetsPerGala[] = $resultArray;
                $i++;
            }
            for (; $i <= $config->max_galaxy; $i++) {
                $avgPlanetsPerGala[] = ['galaxy' => $i, 'AvgPlanetsPerSys' => 0];
            }
        }

        // get gala with min avg systems
        $minAvg = $config->max_planets;
        $galaxy = 0;
        $galaArray = [];
        foreach ($avgPlanetsPerGala as $data) {
            if ($data['AvgPlanetsPerSys'] < $minAvg) {
                $minAvg = $data['AvgPlanetsPerSys'];
            }
        }
        foreach ($avgPlanetsPerGala as $data) {
            if ($data['AvgPlanetsPerSys'] == $minAvg) {
                array_push($galaArray, $data['galaxy']);
            }
        }
        $galaxy = $galaArray[rand(0, count($galaArray) - 1)];

        // get system with planet count for selected gala
        $sql = 'SELECT `system`, count(`planet`) as anz FROM %%PLANETS%%
            WHERE `planet_type` = 1 AND `galaxy` = :gala AND `universe` = :universe GROUP BY `system`;';
        $result = $db->select($sql, [
            ':gala' => $galaxy,
            ':universe' => $universe,
        ]);

        $systems = [];
        if (empty($result)) {
            for ($i = 1; $i <= $config->max_system; $i++) {
                $systems[] = ['system' => $i, 'anz' => 0];
            }
        } else {
            $i = 1;
            foreach ($result as $resultArray) {
                while ($i < $resultArray['system']) {
                    $systems[] = ['system' => $i, 'anz' => 0];
                    $i++;
                }
                $systems[] = $resultArray;
                $i++;
            }
            for (; $i <= $config->max_system; $i++) {
                $systems[] = ['system' => $i, 'anz' => 0];
            }
        }

        $planet = 0;
        $system = 0;

        // get empty systems in selected gala
        for ($planetamount = 0; $planetamount <= $config->max_planets; $planetamount++) {
            $usableSystems = [];
            foreach ($systems as $sysArray) {
                if ($sysArray['anz'] == $planetamount) {
                    $usableSystems[] = $sysArray['system'];
                }
            }

            $planet = 0;
            $system = 0;

            // find random system and random planet inside
            if (!empty($usableSystems)) {
                do {
                    $system = $usableSystems[array_rand($usableSystems)];
                    do {
                        $planet = random_int(round($config->max_planets * 0.2), round($config->max_planets * 0.8));
                    } while (!$this->allowPlanetPosition($planet, null, $universe));
                } while ($this->isPositionFree($universe, $galaxy, $system, $planet) === false);
            }
            if ($this->allowPlanetPosition($planet, null, $universe) && $this->isPositionFree($universe, $galaxy, $system, $planet)) {
                break;
            }
        }
        $pos = [
            'galaxy'   => $galaxy,
            'system'   => $system,
            'planet'   => $planet,
        ];
        return $pos;
    }

    public function blockHP(int $universe): array
    {
        $config = $this->configs->get($universe);
        $db = $this->db;

        //if less than 50 planets, place randomly
        $sql = 'SELECT Count(`id`) AS planetcount FROM %%PLANETS%% WHERE `universe` = :universe;';
        $planetcount = $db->selectSingle($sql, [
            ':universe' => $universe,
        ], 'planetcount');
        if ($planetcount < 50) {
            $pos = $this->randomHP($universe);
            return $pos;
        }

        $minPos = round($config->max_planets * 0.2);
        $maxPos = round($config->max_planets * 0.8);
        $maxPlanets = $maxPos - $minPos;

        //Search for systems with one planet, if none, search for 2 planets
        for ($planetamount = 1; $planetamount < 3; $planetamount++) {
            $sql = 'SELECT p.`galaxy`, p.`system` FROM
                %%PLANETS%% p
                JOIN (
                        SELECT `galaxy`, `system`
                        FROM %%PLANETS%%
                        WHERE `planet_type` = 1
                        AND `universe` = :universe
                        AND `galaxy` <= :maxGala
                        AND `planet` >= :minPos
                        AND `planet` <= :maxPos
                        GROUP BY `galaxy`, `system`
                        HAVING COUNT(`planet`) < :maxPlanets
                    ) as tab ON tab.`galaxy` = p.`galaxy` AND tab.`system` = p.`system`
                JOIN %%USERS%% u on u.`id` = p.`id_owner`
                WHERE p.`planet_type` = 1 AND u.`onlinetime` >= ( :ts - :inactive ) AND p.`universe` = :universe
                GROUP BY p.`galaxy`, p.`system`
                HAVING COUNT(p.`planet`) < :planetamount;';
            $systems = $db->select($sql, [
                ':universe' => $universe,
                ':maxGala' => $config->max_galaxy,
                ':minPos' => $minPos,
                ':maxPos' => $maxPos,
                ':maxPlanets' => $maxPlanets,
                ':planetamount' => $planetamount,
                ':ts' => $this->state->runtime->timestamp,
                ':inactive' => INACTIVE,
            ]);

            if (!empty($systems)) {
                break;
            }
        }

        // if systems found, place planet
        if (!empty($systems)) {
            do {
                $galasys = $systems[array_rand($systems)];
                $galaxy = $galasys['galaxy'];
                $system = $galasys['system'];
                do {
                    $planet = random_int($minPos, $maxPos);
                } while (!$this->allowPlanetPosition($planet, null, $universe));
            } while ($this->isPositionFree($universe, $galaxy, $system, $planet) === false);
            $pos = [
                'galaxy'   => $galaxy,
                'system'   => $system,
                'planet'  => $planet,
            ];
            return $pos;
        }

        // else place random planet
        $pos = $this->randomHP($universe);
        return $pos;
    }

    public function linearHP(int $universe, Config $config): array
    {
        $galaxy = $config->last_galaxy_pos;
        $system = $config->last_system_pos;
        $planet = $config->last_planet_pos;

        if ($galaxy > $config->max_galaxy) {
            $galaxy = 1;
        }

        if ($system > $config->max_system) {
            $system = 1;
        }

        do {
            $planet = random_int(round($config->max_planets * 0.2), round($config->max_planets * 0.8));
            if ($planet < 3) {
                $planet += 1;
            } else {
                if ($system >= $config->max_system) {
                    $system = 1;
                    if ($galaxy >= $config->max_galaxy) {
                        $galaxy = 1;
                    } else {
                        $galaxy += 1;
                    }
                } else {
                    $system += 1;
                }
            }
        } while ($this->isPositionFree($universe, $galaxy, $system, $planet) === false);

        // Update last coordinates to config table
        $config->last_galaxy_pos = $galaxy;
        $config->last_system_pos = $system;
        $config->last_planet_pos = $planet;

        $pos = [
            'galaxy'   => $galaxy,
            'system'   => $system,
            'planet'   => $planet,
        ];
        return $pos;
    }

    public function getNewPlanetPosition(int $universe): array
    {
        $config = $this->configs->get($universe);
        $pos = [];
        if ($config->planet_creation == 1) {
            $pos = $this->randomHP($universe);
        } elseif ($config->planet_creation == 2) {
            $rand_vs_block = rand(0, 1);
            switch ($rand_vs_block) {
                case 0:
                    $pos = $this->randomHP($universe);
                    break;
                case 1:
                    $pos = $this->blockHP($universe);
                    break;
            }
        } else {
            $pos = $this->linearHP($universe, $config);
        }
        return $pos;
    }

    public function createPlayer(
        int $accountId,
        int $universe,
        string $userName,
        ?string $userLanguage = null,
        ?int $galaxy = null,
        ?int $system = null,
        ?int $planet = null,
        ?string $name = null,
        int $authlevel = 0,
        ?string $userIpAddress = null,
        ?string $stb = null,
        ?string $colorSettings = null,
        ?string $missionSettings = null,
        ?string $timezone = null,
        ?string $imageTheme = null,
        ?string $cssTheme = null
    ): array 
    {
        $config = $this->configs->get($universe);
        $imageTheme ??= Theme::resolveDefaultTheme($config->default_theme ?? null);
        $cssTheme ??= Theme::resolveDefaultTheme($config->default_theme ?? null);
        $db = $this->db;

        if (isset($universe, $galaxy, $system, $planet)) {
            $pos = [
                'galaxy'   => $galaxy,
                'system'   => $system,
                'planet' => $planet,
            ];
        } else {
            $pos = $this->getNewPlanetPosition($universe);
        }

        if (isset($universe, $galaxy, $system, $planet)) {
            if ($this->checkPosition($universe, $galaxy, $system, $planet) === false) {
                throw new Exception(
                    sprintf("Try to create a planet at position: %s:%s:%s!", $galaxy, $system, $planet)
                );
            }

            if ($this->isPositionFree($universe, $galaxy, $system, $planet) === false) {
                throw new Exception(
                    sprintf("Position is not empty: %s:%s:%s!", $galaxy, $system, $planet)
                );
            }
        }

        $params = [
            ':username'          => $userName,
            ':authlevel'         => $authlevel,
            ':universe'          => $universe,
            ':registerAddress'   => !empty($userIpAddress) ? $userIpAddress : $this->sessions()->getClientIp(),
            ':onlinetime'        => $this->state->runtime->timestamp,
            ':registerTimestamp' => $this->state->runtime->timestamp,
            ':nameLastChanged'   => 0,
        ];

        $sql = 'INSERT INTO %%USERS%% SET
            `username`		= :username,
            `authlevel`		= :authlevel,
            `universe`		= :universe,
            `ip_at_reg`		= :registerAddress,
            `onlinetime`	= :onlinetime,
            `register_time` = :registerTimestamp,
            `uctime`		= :nameLastChanged;';

        $db->insert($sql, $params);

        $userId = $db->lastInsertId();

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $statsHandler->setValue($userId, AdvancedStatsEnum::HONORPOINTS, 100);

        $timezone = $timezone ?? $config->timezone;
        $params = [
            ':userId'            => $userId,
            ':language'          => $userLanguage,
            ':dpath'             => $imageTheme,
            ':css_dpath'         => $cssTheme,
            ':timezone'          => $timezone,
            ':decimalSeparator'  => (new NumberAbbreviation())->defaultDecimalSeparatorForLang($userLanguage),
        ];

        $sql = 'INSERT INTO %%USERS_SETTINGS%% SET
            `user_id`		= :userId,
            `lang`			= :language,
            `dpath`			= :dpath,
            `css_dpath`    = :css_dpath,
            `timezone`		= :timezone,
            `decimal_separator` = :decimalSeparator;';

        $db->insert($sql, $params);

        // copy settings from the account's player in another universe (the value is that universe's id)
        if ($stb != null && $stb != "default") {
            $this->copyUserSettings($userId, $accountId, (int) $stb, [
                'stb_small_ress', 'stb_med_ress', 'stb_big_ress',
                'stb_small_time', 'stb_med_time', 'stb_big_time', 'stb_enabled',
            ]);
        }
        if ($colorSettings != null && $colorSettings != "default") {
            $this->copyUserSettings($userId, $accountId, (int) $colorSettings, [
                'colorMission2friend', 'colorMission1Own', 'colorMission2Own', 'colorMission3Own',
                'colorMission4Own', 'colorMission5Own', 'colorMission6Own', 'colorMission7Own',
                'colorMission7OwnReturn', 'colorMission8Own', 'colorMission9Own', 'colorMission10Own',
                'colorMission15Own', 'colorMission16Own', 'colorMission17Own', 'colorMissionReturnOwn',
                'colorMission1Foreign', 'colorMission2Foreign', 'colorMission3Foreign',
                'colorMission4Foreign', 'colorMission5Foreign', 'colorMission6Foreign',
                'colorMission7Foreign', 'colorMission8Foreign', 'colorMission9Foreign',
                'colorMission10Foreign', 'colorMission15Foreign', 'colorMission16Foreign',
                'colorMission17Foreign', 'colorMissionReturnForeign', 'colorStaticTimer',
                'colorPositive', 'colorNegative', 'colorNeutral',
            ]);
        }
        if ($missionSettings != null && $missionSettings != "default") {
            $this->copyUserSettings($userId, $accountId, (int) $missionSettings, [
                'prioMission1', 'prioMission2', 'prioMission3', 'prioMission4', 'prioMission5',
                'prioMission6', 'prioMission7', 'prioMission8', 'prioMission9', 'prioMission17',
            ]);
        }

        $planetId = $this->createPlanet(
            $pos['galaxy'],
            $pos['system'],
            $pos['planet'],
            $universe,
            $userId,
            $name,
            true,
            $authlevel
        );

        $currentUserAmount = $config->users_amount + 1;
        $config->users_amount = $currentUserAmount;

        $sql = 'UPDATE %%USERS%% SET
            `galaxy` = :galaxy,
            `system` = :system,
            `planet` = :planet,
            `id_planet` = :planetId
            WHERE `id` = :userId;';

        $db->update($sql, [
            ':galaxy'   => $pos['galaxy'],
            ':system'   => $pos['system'],
            ':planet'   => $pos['planet'],
            ':planetId' => $planetId,
            ':userId'   => $userId,
        ]);

        $sql = 'SELECT MAX(`total_rank`) as `rank` FROM %%STATPOINTS%% WHERE `universe` = :universe AND `stat_type` = :type;';
        $rank = $db->selectSingle($sql, [
            ':universe' => $universe,
            ':type'     => 1,
        ], 'rank');

        $sql = "INSERT INTO %%STATPOINTS%% SET
            `id_owner`   = :userId,
            `universe`   = :universe,
            `stat_type`  = :type,
            `tech_rank`  = :rank,
            `build_rank` = :rank,
            `defs_rank`	 = :rank,
            `fleet_rank` = :rank,
            `total_rank` = :rank;";

        $db->insert($sql, [
           ':universe'  => $universe,
           ':userId'    => $userId,
           ':type'      => 1,
           ':rank'      => $rank + 1,
        ]);

        $sql = 'INSERT INTO %%ACCOUNTS_TO_USERS%% SET
            `account_id`    = :accId,
            user_id         = :userId,
            end_time        = 0;';
        $db->insert($sql, [
            ':userId'    => $userId,
            ':accId'    => $accountId,
        ]);
        $config->save();

        return [
            $userId,
            $planetId
        ];
    }

    public function createPlanet(
        int $galaxy,
        int $system,
        int $planet,
        int $universe,
        int $userId,
        ?string $name = null,
        bool $isHome = false,
        int $authlevel = 0
    ): int 
    {
        $LNG = & $this->state->LNG;

        if ($this->checkPosition($universe, $galaxy, $system, $planet) === false) {
            throw new Exception(
                sprintf("Try to create a planet at position: %s:%s:%s!", $galaxy, $system, $planet)
            );
        }

        if ($this->isPositionFree($universe, $galaxy, $system, $planet) === false) {
            throw new Exception(
                sprintf("Position is not empty: %s:%s:%s!", $galaxy, $system, $planet)
            );
        }
        $db = $this->db;

        $sql = 'SELECT count(`id`) as anz FROM %%PLANETS%% WHERE `id_owner` = :userId AND `planet_type` = 1';
        $planetArray = $db->selectSingle($sql, [
            ':userId' => $userId,
        ]);

        $planetData = [];
        require 'src/Game/Support/planet_data.php';

        $planetDataBackend = $this->getPlanetDataPlanetConfig();

        $config = $this->configs->get($universe);
        $positionBasedRessourceBonus = $config->planet_ressource_bonus;

        $metalBonusPercent = 0;
        $crystalBonusPercent = 0;
        $deuteriumBonusPercent = 0;

        $dataIndex = (int) ceil($planet / ($config->max_planets / count($planetDataBackend)));

        if ($isHome) {
            $maxFields = $config->initial_fields;
            $maxTemperature = $config->initial_temp;
            if ($positionBasedRessourceBonus) {
                $metalBonusPercent = 0;
                $crystalBonusPercent = 0;
                $deuteriumBonusPercent = 0;
            }
        } elseif (!empty($planetArray) && $planetArray['anz'] == 1) {
            if ($positionBasedRessourceBonus) {
                $metalBonusPercent = $planetDataBackend[$dataIndex - 1]['metalBonusPercent'];
                $crystalBonusPercent = $planetDataBackend[$dataIndex - 1]['crystalBonusPercent'];
                $deuteriumBonusPercent = $planetDataBackend[$dataIndex - 1]['deuteriumBonusPercent'];
            }
            $addToAvgSize = (int) floor(($planetDataBackend[$dataIndex - 1]['fields_max'] - $planetDataBackend[$dataIndex - 1]['fields_min']) / 2);
            $avgSize = $planetDataBackend[$dataIndex - 1]['fields_min'] + $addToAvgSize;
            $maxFields = (int) floor($avgSize * $config->planet_size_factor);
            $addToAvgTemperature = (int) floor(($planetDataBackend[$dataIndex - 1]['temp_max'] - $planetDataBackend[$dataIndex - 1]['temp_min']) / 2);
            $maxTemperature = $planetDataBackend[$dataIndex - 1]['temp_min'] + $addToAvgTemperature;
        } else {
            if ($positionBasedRessourceBonus) {
                $metalBonusPercent = $planetDataBackend[$dataIndex - 1]['metalBonusPercent'];
                $crystalBonusPercent = $planetDataBackend[$dataIndex - 1]['crystalBonusPercent'];
                $deuteriumBonusPercent = $planetDataBackend[$dataIndex - 1]['deuteriumBonusPercent'];
            }
            $maxFields = (int) floor(random_int($planetDataBackend[$dataIndex - 1]['fields_min'], $planetDataBackend[$dataIndex - 1]['fields_max']) * $config->planet_size_factor);
            $maxTemperature = random_int($planetDataBackend[$dataIndex - 1]['temp_min'], $planetDataBackend[$dataIndex - 1]['temp_max']);
        }

        $sql = 'SELECT `galaxy` FROM %%USERS%% WHERE `id` = :userId;';
        $hpGala = $db->selectSingle($sql, [
            ':userId' => $userId,
        ], 'galaxy');
        $this->playerUtilAchievements()->checkPlayerUtilAchievements40($isHome, $hpGala, $userId, $galaxy, $config);
        $minTemperature = $maxTemperature - 40;

        $diameter = (int) floor(1000 * sqrt($maxFields));

        $sql = 'SELECT `image` FROM %%PLANETS%% WHERE `id_owner` = :userId AND `destruyed` = 0';
        $planetPicturesAchieved = $db->select($sql, [
            ':userId' => $userId,
        ]);

        $planetPictures = [];
        foreach ($planetPicturesAchieved as $image => $value) {
            array_push($planetPictures, $value["image"]);
        }

        $enabledAllPlanetPictures = $config->all_planet_pictures;
        $maxImages = 0;
        do {
            if ($enabledAllPlanetPictures) {
                $planetData[$dataIndex]['image'] = getAllPlanetPictures();
            }
            $imageNames = array_keys($planetData[$dataIndex]['image']);
            $imageNameType = $imageNames[array_rand($imageNames)];
            $imageName = $imageNameType;
            $imageName .= 'planet';
            $imageName .= $planetData[$dataIndex]['image'][$imageNameType] < 10 ? '0' : '';
            $imageName .= $planetData[$dataIndex]['image'][$imageNameType];
            $maxImages++;
        } while (in_array($imageName, $planetPictures) && ($maxImages < MAXPLANETPICTURECOUNT) && ($enabledAllPlanetPictures));

        if (empty($name)) {
            $name = $isHome ? $LNG['fcm_mainplanet'] : $LNG['fcp_colony'];
        }

        $params = [
            ':name'                     => $name,
            ':universe'                 => $universe,
            ':userId'                   => $userId,
            ':galaxy'                   => $galaxy,
            ':system'                   => $system,
            ':planet'                   => $planet,
            ':updateTimestamp'          => $this->state->runtime->timestamp,
            ':type'                     => 1,
            ':imageName'                => $imageName,
            ':diameter'                 => $diameter,
            ':maxFields'                => $maxFields,
            ':minTemperature'           => $minTemperature,
            ':maxTemperature'           => $maxTemperature,
            ':metal_start'              => $config->metal_start,
            ':metalBonusPercent'        => $metalBonusPercent,
            ':crystal_start'            => $config->crystal_start,
            ':crystalBonusPercent'      => $crystalBonusPercent,
            ':deuterium_start'          => $config->deuterium_start,
            ':deuteriumBonusPercent'    => $deuteriumBonusPercent,
            ':creation_time'            => $this->state->runtime->timestamp,
        ];

        $sql = 'INSERT INTO %%PLANETS%% SET
            `name`		                = :name,
            `universe`                  = :universe,
            `id_owner`                  = :userId,
            `galaxy`		            = :galaxy,
            `system`		            = :system,
            `planet`		            = :planet,
            `last_update`               = :updateTimestamp,
            `planet_type`               = :type,
            `image`		                = :imageName,
            `diameter`                  = :diameter,
            `field_max`                 = :maxFields,
            `temp_min` 	                = :minTemperature,
            `temp_max` 	                = :maxTemperature,
            `metal`		                = :metal_start,
            `metal_bonus_percent`       = :metalBonusPercent,
            `crystal`		            = :crystal_start,
            `crystal_bonus_percent`     = :crystalBonusPercent,
            `deuterium`                 = :deuterium_start,
            `deuterium_bonus_percent`   = :deuteriumBonusPercent,
            `creation_time`             = :creation_time;';

        $db->insert($sql, $params);

        $planetId = $db->lastInsertId();

        return $planetId;
    }

    public function createMoon(
        int $universe,
        int $galaxy,
        int $system,
        int $planet,
        int $userId,
        float $chance,
        ?string $moonName = null,
        ?int $diameter = null,
    ): int
    {
        $LNG = & $this->state->LNG;

        $db = $this->db;

        $sql = 'SELECT `id_luna`, `planet_type`, `id`, `name`, `temp_max`, `temp_min`
            FROM %%PLANETS%%
            WHERE `universe` = :universe
            AND `galaxy` = :galaxy
            AND `system` = :system
            AND `planet` = :planet
            AND `planet_type` = :type;';

        $parentPlanet = $db->selectSingle($sql, [
            ':universe' => $universe,
            ':galaxy'   => $galaxy,
            ':system'   => $system,
            ':planet'   => $planet,
            ':type'     => 1,
        ]);

        if ($parentPlanet['id_luna'] != 0) {
            return false;
        }

        $config = $this->configs->get($universe);
        $moon_size_factor = $config->moon_size_factor;

        if (is_null($diameter)) {
            $diameter = floor(pow(random_int(10, 20) + 3 * $chance, 0.5) * 1000 * $moon_size_factor);
        }

        $maxTemperature = $parentPlanet['temp_max'] - random_int(10, 45);
        $minTemperature = $parentPlanet['temp_min'] - random_int(10, 45);

        if (empty($moonName)) {
            $moonName = $LNG['type_planet_3'] ?? 'Mond';
        }

        $sql = 'INSERT INTO %%PLANETS%% SET
            `name`				= :name,
            `id_owner`			= :owner,
            `universe`			= :universe,
            `galaxy`			= :galaxy,
            `system`			= :system,
            `planet`			= :planet,
            `last_update`		= :updateTimestamp,
            `planet_type`		= :type,
            `image`				= :image,
            `diameter`			= :diameter,
            `field_max`			= :fields,
            `temp_min`			= :minTemperature,
            `temp_max`			= :maxTemperature,
            `metal`				= :metal,
            `metal_perhour`		= :metPerHour,
            `crystal`			= :crystal,
            `crystal_perhour`	= :cryPerHour,
            `deuterium`			= :deuterium,
            `deuterium_perhour` = :deuPerHour;';

        $db->insert($sql, [
            ':name'             => $moonName,
            ':owner'            => $userId,
            ':universe'         => $universe,
            ':galaxy'           => $galaxy,
            ':system'           => $system,
            ':planet'           => $planet,
            ':updateTimestamp'  => $this->state->runtime->timestamp,
            ':type'             => 3,
            ':image'            => 'mond0'.rand(1, 9),
            ':diameter'         => $diameter,
            ':fields'           => 1,
            ':minTemperature'   => $minTemperature,
            ':maxTemperature'   => $maxTemperature,
            ':metal'            => 0,
            ':metPerHour'       => 0,
            ':crystal'          => 0,
            ':cryPerHour'       => 0,
            ':deuterium'        => 0,
            ':deuPerHour'       => 0,
        ]);

        $moonId = $db->lastInsertId();

        $sql = 'UPDATE %%PLANETS%% SET `id_luna` = :moonId WHERE `id` = :planetId;';

        $db->update($sql, [
            ':moonId'   => $moonId,
            ':planetId' => $parentPlanet['id'],
        ]);

        return $moonId;
    }

    /**
     * Copies the given users_settings columns from the account's player in $sourceUniverse to $userId.
     * Only players of the same account are used as source.
     *
     * @param string[] $columns fixed column names (never user input)
     */
    private function copyUserSettings(int $userId, int $accountId, int $sourceUniverse, array $columns): void
    {
        $assignments = [];
        foreach ($columns as $column) {
            $assignments[] = 'dest.`' . $column . '` = src.`' . $column . '`';
        }

        $sql = 'UPDATE %%USERS_SETTINGS%% dest
            JOIN (
                SELECT us.*
                FROM %%USERS_SETTINGS%% us
                INNER JOIN %%ACCOUNTS_TO_USERS%% atu ON us.`user_id` = atu.`user_id`
                INNER JOIN %%USERS%% u ON us.`user_id` = u.`id`
                WHERE atu.`account_id` = :accId AND u.`universe` = :universe AND us.`user_id` != :userId
                LIMIT 1
            ) AS src
            SET ' . implode(', ', $assignments) . '
            WHERE dest.`user_id` = :userId;';

        $this->db->update($sql, [
            ':accId' => $accountId,
            ':universe' => $sourceUniverse,
            ':userId' => $userId,
        ]);
    }

    public function removeFromAlliance(array $userData): void
    {
        $db = $this->db;
        if (!empty($userData['ally_id'])) {
            $sql = 'SELECT `ally_owner`, `ally_members` FROM %%ALLIANCE%% WHERE `id` = :allianceId;';
            $allyData = $db->selectSingle($sql, [
                ':allianceId'   => $userData['ally_id']
            ]);

            if ($allyData['ally_members'] > 1) {
                if ($allyData['ally_owner'] == $userData['id']) { // Removed player is ally leader
                    // Find new leader by respecting rank settings
                    $sql = 'SELECT u.`id` FROM %%ALLIANCE_RANK%% r
                        JOIN %%USERS%% u ON u.`ally_rank_id` = r.`rankID` AND u.`ally_id` = r.`allianceID`
                        WHERE r.`allianceID` = :allianceId
                          AND u.`id` != :removedId
                          AND u.`onlinetime` > :onlinetime
                          AND ( r.`transfer` = 1 OR r.`admin` = 1 OR r.`kick` = 1 OR r.`diplomatic` = 1 OR r.`manageusers` = 1 OR r.`ranks` = 1 )
                          ORDER BY r.`transfer` DESC, r.`admin` DESC, r.`kick` DESC, r.`diplomatic` DESC, r.`manageusers` DESC, r.`ranks` DESC, u.`onlinetime` DESC
                          LIMIT 1;';
                    $newLeaders = $db->select($sql, [
                        ':allianceId'   => $userData['ally_id'],
                        ':removedId'    => $userData['id'],
                        ':onlinetime'   => $this->state->runtime->timestamp - TIME_72_HOURS,
                    ]);

                    $newLeader = 0;
                    if (!empty($newLeaders)) { // New leader by rank found?
                        $newLeader = $newLeaders[0]['id'];
                    } else {
                        // Find new leader by onlinetime
                        // (any other member of the alliance, ranked or not)
                        $sql = 'SELECT u.`id` FROM %%USERS%% u
                            WHERE u.`ally_id` = :allianceId AND u.`id` != :removedId
                            ORDER BY u.`onlinetime` DESC LIMIT 1;';
                        $newLeader = $db->selectSingle($sql, [
                            ':allianceId'   => $userData['ally_id'],
                            ':removedId'    => $userData['id'],
                        ], 'id');
                    }

                    if (!empty($newLeader)) { // New leader found?
                        // Update rank of new leader
                        $sql = 'UPDATE %%USERS%% SET `ally_rank_id` = 0 WHERE `id` = :UserID;';
                        $db->update($sql, [
                            ':UserID' => $newLeader,
                        ]);

                        // Update ally_owner to new leader and reduce member amount
                        $sql = 'UPDATE %%ALLIANCE%% SET `ally_owner` = :UserID, `ally_members` = `ally_members` - 1 WHERE `id` = :allianceId;';
                        $db->update($sql, [
                            ':allianceId'   => $userData['ally_id'],
                            ':UserID' => $newLeader,
                        ]);
                    } else {
                        // No new leader found, just reduce member amount
                        $sql = 'UPDATE %%ALLIANCE%% SET `ally_members` = `ally_members` - 1 WHERE `id` = :allianceId;';
                        $db->update($sql, [
                            ':allianceId'   => $userData['ally_id']
                        ]);
                    }
                } else {
                    // Removed player is not leader, just reduce member amount
                    $sql = 'UPDATE %%ALLIANCE%% SET `ally_members` = `ally_members` - 1 WHERE `id` = :allianceId;';
                    $db->update($sql, [
                        ':allianceId'   => $userData['ally_id']
                    ]);
                }
            } else {
                // Delete alliance, since there are no members
                $sql = 'DELETE FROM %%ALLIANCE%% WHERE `id` = :allianceId;';
                $db->delete($sql, [
                    ':allianceId'   => $userData['ally_id']
                ]);

                $sql = 'DELETE FROM %%ALLIANCE_RANK%% WHERE `allianceID` = :allianceId;';
                $db->delete($sql, [
                    ':allianceId'   => $userData['ally_id']
                ]);

                $sql = 'DELETE FROM %%ALLIANCE_REQUEST%% WHERE `allianceID` = :allianceId;';
                $db->delete($sql, [
                    ':allianceId'   => $userData['ally_id']
                ]);

                $sql = 'DELETE FROM %%STATPOINTS%% WHERE `stat_type` = :type AND `id_owner` = :allianceId;';
                $db->delete($sql, [
                    ':allianceId'   => $userData['ally_id'],
                    ':type'         => 2
                ]);

                $sql = 'DELETE FROM %%STATPOINTS_HISTORY%% WHERE `stat_type` = :type AND `id_owner` = :allianceId;';
                $db->delete($sql, [
                    ':allianceId'   => $userData['ally_id'],
                    ':type'         => 2
                ]);

                $sql = 'UPDATE %%STATPOINTS%% SET `id_ally` = :resetId WHERE `id_ally` = :allianceId;';
                $db->update($sql, [
                    ':allianceId'   => $userData['ally_id'],
                    ':resetId'      => 0
                ]);
            }

            $sql = 'UPDATE %%USERS%% SET `ally_id` = 0, `ally_register_time` = 0, `ally_rank_id` = 0 WHERE `id` = :UserID;';
            $db->update($sql, [
                ':UserID' => $userData['id']
            ]);

            $sql = 'UPDATE %%STATPOINTS%% SET `id_ally` = :resetId WHERE `stat_type` = :type AND `id_owner` = :UserID;';
            $db->update($sql, [
                ':UserID'   => $userData['id'],
                ':resetId'  => 0,
                ':type'     => 1,
            ]);
        }

        $sql = 'DELETE FROM %%ALLIANCE_REQUEST%% WHERE `userID` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userData['id']
        ]);
    }

    public function removeFromBuddy(int $userId): void
    {
        $db = $this->db;
        $sql = 'DELETE FROM %%BUDDY%% WHERE `owner` = :userId OR `sender` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);
    }

    public function deletePlayer(int $userId): bool
    {
        if (ROOT_USER == $userId) {
            // superuser can not be deleted.
            throw new Exception("Superuser #" . ROOT_USER . " can't be deleted!");
        }

        $db = $this->db;
        $sql = 'SELECT `id`, `universe`, `ally_id` FROM %%USERS%% WHERE `id` = :userId;';
        $userData = $db->selectSingle($sql, [
            ':userId'   => $userId
        ]);

        if (empty($userData)) {
            return false;
        }

        $this->removeFromAlliance($userData);

        $this->removeFromBuddy($userId);

        // marketplace offers of the player's fleets would otherwise stay behind without a fleet
        $sql = 'DELETE t FROM %%TRADES%% t
            INNER JOIN %%FLEETS%% f ON f.`id` = t.`seller_fleet_id`
            WHERE f.`owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE %%FLEETS%%, %%FLEETS_EVENT%%
            FROM %%FLEETS%% LEFT JOIN %%FLEETS_EVENT%% on `id` = fleetId
            WHERE `owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        // Messages are shared rows: every recipient (and the sender) owns a row in USERS_TO_MESSAGES.
        // Remove only this player's copies and drop the message itself once nobody holds a copy anymore.
        // Messages this player sent stay in the recipients' inboxes (sender_id is nulled by the FK).
        $messageIds = $db->select('SELECT `message_id` FROM %%USERS_TO_MESSAGES%% WHERE `user_id` = :userId;', [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE FROM %%USERS_TO_MESSAGES%% WHERE `user_id` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        foreach (array_column($messageIds, 'message_id') as $messageId) {
            $sql = 'DELETE FROM %%MESSAGES%% WHERE `message_id` = :messageId
                AND NOT EXISTS (SELECT 1 FROM %%USERS_TO_MESSAGES%% um WHERE um.`message_id` = :messageId2);';
            $db->delete($sql, [
                ':messageId'  => $messageId,
                ':messageId2' => $messageId
            ]);
        }

        // Per-player secondary data. Kept on purpose: USERS_BANNED (public ban list), USERS_TO_TOPKB and
        // TOPKB/RAPORTS (battle reports and hall of fame, other players are part of them), LOG/LOG_FLEETS (audit).
        $perUserTables = [
            'USERS_BLOCKLIST'       => ['blocking_user', 'blocked_user'],
            'ALLIANCE_REQUEST'      => ['userID'],
            'USERS_COMMENTS'        => ['user_id'],
            'USERS_ANALYTICS'       => ['userID'],
            'USERS_TO_ACHIEVEMENTS' => ['userID'],
            'USERS_TO_ACS'          => ['userID'],
            'USERS_TUTORIAL'        => ['userID'],
            'USERS_TUTORIAL_GOAL'   => ['userID'],
            'EXPEDITION_USER_DECK'  => ['user_id'],
            'MULTI_TO_USERS'        => ['userID'],
            'SHORTCUTS'             => ['ownerID'],
            'SESSION'               => ['userID'],
            'RECORDS'               => ['userID'],
            'ADVANCED_STATS_UNIVERSE' => ['userId'],
            'PHALANX_LOG'           => ['owner', 'target'],
        ];
        foreach ($perUserTables as $table => $columns) {
            $where = implode(' OR ', array_map(static fn($column) => '`' . $column . '` = :userId', $columns));
            $db->delete('DELETE FROM %%' . $table . '%% WHERE ' . $where . ';', [
                ':userId'   => $userId
            ]);
        }

        $sql = 'DELETE a FROM %%TICKETS_ANSWER%% a INNER JOIN %%TICKETS%% t ON t.`ticketID` = a.`ticketID`
            WHERE t.`ownerID` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE FROM %%TICKETS%% WHERE `ownerID` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE FROM %%NOTES%% WHERE `owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE %%PLANETS%%, %%PLANET_WRECKFIELD%%
            FROM %%PLANETS%% LEFT JOIN %%PLANET_WRECKFIELD%% on `planetID` = id
            WHERE `id_owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE FROM %%USERS_SETTINGS%% WHERE `user_id` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE FROM %%USERS%% WHERE `id` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        $sql = 'DELETE FROM %%STATPOINTS%% WHERE `stat_type` = :type AND `id_owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
            ':type'     => 1
        ]);

        $sql = 'DELETE FROM %%STATPOINTS_HISTORY%% WHERE `stat_type` = :type AND `id_owner` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId,
            ':type'     => 1
        ]);

        $sql = 'DELETE FROM %%ADVANCED_STATS%% WHERE `userId` = :userId;';
        $db->delete($sql, [
            ':userId'    => $userId,
        ]);

        $sql = 'DELETE FROM %%ADVANCED_STATS_HISTORY%% WHERE `userId` = :userId;';
        $db->delete($sql, [
            ':userId'    => $userId,
        ]);

        $fleetIds = $db->select('SELECT `id` FROM %%FLEETS%% WHERE `target_owner` = :userId;', [
            ':userId'   => $userId
        ]);

        foreach ($fleetIds as $fleetId) {
            $this->fleetFunctions()->sendFleetBack(['id' => $userId], $fleetId['id']);
        }

        $sql = 'DELETE FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userId;';
        $db->delete($sql, [
            ':userId'   => $userId
        ]);

        /*
        $sql = 'UPDATE %%UNIVERSE%% SET `userAmount` = `userAmount` - 1 WHERE `universe` = :universe;';
        $db->update($sql, array(
            ':universe' => $userData['universe']
        ));

        Cache::get()->flush('universe');
        */

        return true;
    }

    /**
     * Delete all Planets of players and recreate them according to the algorithm, currently selected.
     *
     * @param int	 		$universe       The id of the Universe
     */
    public function reshuffleAllPlanets(int $universe): string|bool
    {
        $USER = & $this->state->USER;
        $db = $this->db;
        $config = $this->configs->get($universe);
        $sql = 'SELECT MAX(count) AS max FROM(SELECT count(*) AS count, `id_owner` FROM %%PLANETS%% WHERE `universe` = :uni GROUP BY `id_owner`) AS plani;';
        $maxPlanetAmount = $db->selectSingle($sql, [
            ':uni' => $universe
        ], 'max');
        $uniStatus = $config->uni_status;

        if ($maxPlanetAmount > 1 || $uniStatus != STATUS_CLOSED || $USER['authlevel'] != AUTH_ADM) {
            $result = "";
            $LNG = & $this->state->LNG;
            if ($maxPlanetAmount > 1) {
                $result = $LNG['rp_planet_error'];
            } elseif ($uniStatus != STATUS_CLOSED) {
                $result = $LNG['rp_universe_error'];
            } elseif ($USER['authlevel'] != AUTH_ADM) {
                $result = $LNG['rp_rights_error'];
            }
            return $result;
        }
        $sql = 'UPDATE %%USERS%% SET `onlinetime` = :onlinetime WHERE `universe` = :universe;';
        $db->update($sql, [
            ':onlinetime' => $this->state->runtime->timestamp,
            ':universe' => $universe
        ]);
        $sql = 'SELECT `id` FROM %%USERS%% WHERE `authlevel` = :auth AND `universe` = :universe ORDER BY `id`;';
        $userIds = $db->select($sql, [
            ':auth'      => AUTH_USR,
            ':universe' => $universe,
        ]);
        foreach ($userIds as $userId) {
            $sql = 'DELETE FROM %%PLANETS%% WHERE `id_owner` = :userId;';
            $db->delete($sql, [
                ':userId'   => $userId['id'],
            ]);
        }
        $config->last_galaxy_pos = 1;
        $config->last_system_pos = 1;
        foreach ($userIds as $userId) {
            $pos = $this->getNewPlanetPosition($universe);
            
            if (isset($universe, $pos['galaxy'], $pos['system'], $pos['planet'])) {
                if ($this->checkPosition($universe, $pos['galaxy'], $pos['system'], $pos['planet']) === false) {
                    throw new Exception(
                        sprintf("Try to create a planet at position: %s:%s:%s!", $pos['galaxy'], $pos['system'], $pos['planet'])
                    );
                }

                if ($this->isPositionFree($universe, $pos['galaxy'], $pos['system'], $pos['planet']) === false) {
                    throw new Exception(
                        sprintf("Position is not empty: %s:%s:%s!", $pos['galaxy'], $pos['system'], $pos['planet'])
                    );
                }
            }
            $sql = 'SELECT `lang` FROM %%USERS_SETTINGS%% WHERE `user_id` = :userId;';
            $lang = $db->selectSingle($sql, [
                ':userId' => $userId['id'],
            ], 'lang');
            $LNG    = new Language($this->configs, $lang);
            $this->state->LNG = $LNG;
            $LNG = & $this->state->LNG;
            $LNG->includeData(['L18N', 'INGAME', 'TECH', 'CUSTOM']);
            $name = $LNG['fcm_mainplanet'];
            $planetId = $this->createPlanet(
                $pos['galaxy'],
                $pos['system'],
                $pos['planet'],
                $universe,
                $userId['id'],
                $name,
                true
            );
            $sql = 'UPDATE %%USERS%% SET
                `galaxy` = :galaxy,
                `system` = :system,
                `planet` = :planet,
                `id_planet` = :planetId
                WHERE `id` = :userId;';

            $db->update($sql, [
                ':galaxy'   => $pos['galaxy'],
                ':system'   => $pos['system'],
                ':planet'   => $pos['planet'],
                ':planetId' => $planetId,
                ':userId'   => $userId['id'],
            ]);
        }
        return true;
    }
    public function deletePlanet(int $planetId): bool
    {
        $db = $this->db;
        $sql = 'SELECT `id_owner`, `planet_type`, `id_luna` FROM %%PLANETS%%
                    WHERE `id` = :planetId AND `id` NOT IN (SELECT `id_planet` FROM %%USERS%%);';
        $planetData = $db->selectSingle($sql, [
            ':planetId' => $planetId
        ]);

        if (empty($planetData)) {
            throw new Exception("Can not found planet #" . $planetId . "!");
        }

        $sql = 'SELECT `id` FROM %%FLEETS%% WHERE `end_id` = :planetId
                    OR (`end_type` = :type AND `end_id` = :moondId);';
        $fleetIds = $db->select($sql, [
            ':planetId' => $planetId,
            ':type'     => TYPE_MOON,
            ':moondId'  => $planetData['id_luna']
        ]);

        foreach ($fleetIds as $fleetId) {
            $this->fleetFunctions()->sendFleetBack(['id' => $planetData['id_owner']], $fleetId['id']);
        }

        if ($planetData['planet_type'] == TYPE_MOON) {
            $sql = 'DELETE FROM %%PLANETS%% WHERE id = :planetId;';
            $db->delete($sql, [
                ':planetId' => $planetId
            ]);

            $sql = 'UPDATE %%PLANETS%% SET `id_luna` = :resetId WHERE `id_luna` = :planetId;';
            $db->update($sql, [
                ':resetId'  => 0,
                ':planetId' => $planetId
            ]);
        } else {
            $this->playerUtilAchievements()->checkPlayerUtilAchievementsDeletion($planetData, $planetId);
            $sql = 'UPDATE %%PLANETS%% SET `destruyed` = :time WHERE `id` = :planetID;';
            $db->update($sql, [
                ':time'   => $this->state->runtime->timestamp + 86400,
                ':planetID' => $planetId,
            ]);
            $sql = 'DELETE FROM %%PLANETS%% WHERE `id` = :lunaID;';
            $db->delete($sql, [
                ':lunaID' => $planetData['id_luna']
            ]);
            $sql = 'DELETE FROM %%PLANET_WRECKFIELD%% WHERE `planetID` = :planetID;';
            $db->delete($sql, [
                ':planetID' => $planetId,
            ]);
        }
        $session	= $this->sessions()->load();
        $session->planetId      = 0;
        return true;
    }

    private function getAstroTech(array $USER): int
    {
        $resource = & $this->state->resource;

        $astroTech = $USER[$resource[124]];

        $CurrentQueue = !empty($USER['b_tech_queue']) ? unserialize($USER['b_tech_queue']) : [];
        if (!empty($CurrentQueue) && count($CurrentQueue) > 0) {
            foreach ($CurrentQueue as $ListIDArray) {
                if ($ListIDArray[0] == 124 && $ListIDArray[3] <= $this->state->runtime->timestamp) {
                    $astroTech++;
                }
            }
        }

        return $astroTech;
    }

    public function maxPlanetCount(array $USER): int
    {
        $resource = & $this->state->resource;
        $config = $this->configs->get($USER['universe']);

        $planetPerTech = $config->max_additional_planets;

        if ($config->max_initial_planets == 0) {
            $planetPerTech = 999;
        }

        if ($config->max_initial_planets == 0) {
            $planetPerBonus = 999;
        }

        // http://owiki.de/index.php/Astrophysik#.C3.9Cbersicht
        return (int) ceil(
            $config->max_initial_planets + min(
                $planetPerTech,
                $this->getAstroTech($USER) * $config->planets_per_tech
            )
        );
    }

    public function currentPlanetCount(array $USER): int
    {
        $db = $this->db;
        $sql = 'SELECT COUNT(*) as state
            FROM %%PLANETS%%
            WHERE `id_owner`	= :userId
            AND `planet_type`	= :type
            AND `destruyed`		= :destroyed;';

        $currentPlanetCount = $db->selectSingle($sql, [
            ':userId'       => $USER['id'],
            ':type'         => 1,
            ':destroyed'    => 0,
        ], 'state');

        return $currentPlanetCount;
    }

    public function allowPlanetPosition(int $position, ?array $USER = null, int $universe = ROOT_UNI): bool
    {
        // http://owiki.de/index.php/Astrophysik#.C3.9Cbersicht

        if (isset($USER)) {
            $config = $this->configs->get($USER['universe']);
            $astroTech = $this->getAstroTech($USER);

            // the planet counts only matter when astro tech is 0 (max(astroTech, 1) changes nothing otherwise)
            if ($astroTech < 1 && $this->currentPlanetCount($USER) < $this->maxPlanetCount($USER)) {
                $astroTech = 1;
            }
        } else {
            $config = $this->configs->get($universe);
            $astroTech = 1;
        }

        switch ($position) {
            case 0:
                return false;
            case 1:
            case ($config->max_planets):
                return $astroTech >= 8;
            case 2:
            case ($config->max_planets - 1):
                return $astroTech >= 6;
            case 3:
            case ($config->max_planets - 2):
                return $astroTech >= 4;
            default:
                return $astroTech >= 1 && $config->max_planets >= $position;
        }
    }

    public function forwardMessage(int|array $userId, int $senderId, int $messageId): void
    {
        $USER = & $this->state->USER;
        $db = $this->db;

        if (!is_array($userId)) {
            $userId = [$userId];
        }

        $sqlUserMessage = 'INSERT IGNORE INTO %%USERS_TO_MESSAGES%% SET
            `message_id`        = :messageId,
            `user_id`           = :userId,
            `sender_id`         = :senderId,
            `forwarder_id`      = :forwarder_id,
            `message_unread`    = :messageUnread,
            `message_forwarded` = :messageForwarded;';

        foreach ($userId as $receiverId) {
            $db->insert($sqlUserMessage, [
                ':messageId'        => $messageId,
                ':userId'           => $receiverId,
                ':forwarder_id'     => $USER['id'],
                ':senderId'         => $senderId === 0 ? null : $senderId,
                ':messageUnread'    => 1,
                ':messageForwarded' => 1,
            ]);
        }
    }

    public function sendMessage(
        int|array $userId,
        int $senderId,
        string $senderName,
        int $messageType,
        string $subject,
        string $text,
        int $time,
        int $unread = 1,
        ?int $universe = null,
        ?string $groupKey = null
    ): void {
        if (is_null($universe)) {
            $universe = $this->universe()->current();
        }

        if (!empty($groupKey) && $messageType !== MESSAGE_TYPE_USER && !str_starts_with($groupKey, 'm_')) {
            $groupKey = 'm_' . $groupKey;
        }

        if (!is_array($userId)) {
            $userId = [$userId];
        }

        $db = $this->db;

        $sql = 'INSERT INTO %%MESSAGES%% SET
            `message_group`     = :groupKey,
            `message_owner`     = :userId,
            `message_sender`    = :sender,
            `message_time`      = :time,
            `message_type`      = :type,
            `message_from`      = :from,
            `message_subject`   = :subject,
            `message_text`      = :text,
            `message_unread`    = :unread,
            `message_universe`  = :universe;';

        $db->insert($sql, [
            ':userId'   => $userId[0],
            ':groupKey' => empty($groupKey) ? null : $groupKey,
            ':sender'   => $senderId,
            ':time'     => $time,
            ':type'     => $messageType,
            ':from'     => $senderName,
            ':subject'  => $subject,
            ':text'     => $text,
            ':unread'   => $unread,
            ':universe' => $universe,
        ]);

        $lastMessageId = $db->lastInsertId();
        if (($messageType === MESSAGE_TYPE_USER || $messageType === MESSAGE_TYPE_SYSTEM) && empty($groupKey)) {
            if ($messageType == MESSAGE_TYPE_USER) {
                $groupKey = 'u_' . $lastMessageId;
            } else {
                $groupKey = 'm_' . $lastMessageId;
            }
            $sql = 'UPDATE %%MESSAGES%% SET `message_group` = :groupKey WHERE `message_id` = :messageId;';
            $db->update($sql, [
                ':groupKey' => $groupKey,
                ':messageId' => $lastMessageId,
            ]);
        }

        $sqlUserMessage = 'INSERT INTO %%USERS_TO_MESSAGES%% SET
            `message_id`     = :messageId,
            `user_id`        = :userId,
            `sender_id`      = :senderId,
            `forwarder_id`   = :forwarder_id,
            `message_unread` = :messageUnread,
            `created_at`     = :created_at;';

        foreach ($userId as $receiverId) {
            $db->insert($sqlUserMessage, [
                ':messageId'        => $lastMessageId,
                ':userId'           => $receiverId,
                ':forwarder_id'     => null,
                ':senderId'         => $senderId === 0 ? null : $senderId,
                ':messageUnread'    => 1,
                ':created_at'       => $time,
            ]);
        }

        if ($senderId !== 0) {
            $db->insert($sqlUserMessage, [
                ':messageId'        => $lastMessageId,
                ':userId'           => $senderId,
                ':forwarder_id'     => null,
                ':senderId'         => $senderId,
                ':messageUnread'    => 0,
                ':created_at'       => $time,
            ]);
        }

        if ($messageType === MESSAGE_TYPE_USER) {
            foreach ($userId as $receiverId) {
                $sql = 'SELECT `username`, `discord_id`, `discord_hook` FROM %%USERS%%
                WHERE `universe` = :universe AND `id` = :userID AND `authlevel` > 0;';
                $admin = $db->selectSingle($sql, [
                    ':universe' => $universe,
                    ':userID' => $receiverId,
                ]);

                if (!empty($admin['discord_hook'])) {
                    $title = '';
                    if (!empty($admin['discord_id'])) {
                        $title = '<@' . $admin['discord_id'] . '> hat eine neue Ingame-Nachricht erhalten.';
                    } else {
                        $title = '<@' . $admin['username'] . '> hat eine neue Ingame-Nachricht erhalten.';
                    }

                    $content = [
                        'Universum' => $universe,
                        'Absender' => $senderId . ' - ' . $senderName,
                        'Betreff' => $subject,
                    ];

                    $this->discord()->sendMessage($admin['discord_hook'], $title, $content);
                }
            }

        }

        foreach ($userId as $receiverId) {
            $this->playerUtilAchievements()->checkPlayerUtilAchievements36($receiverId, $text);
        }
    }

    public function clearPlanets(array $USER): void
    {
        $reslist = & $this->state->reslist;
        $resource = & $this->state->resource;

        $db = $this->db;

        $params = [];
        $params[] = $resource[RESOURCE_METAL] . ' = 0';
        $params[] = $resource[RESOURCE_CRYSTAL] . ' = 0';
        $params[] = $resource[RESOURCE_DEUT] . ' = 0';

        foreach ($reslist['fleet'] as $element) {
            $params[] = $resource[$element] . ' = 0';
        }

        foreach ($reslist['defense'] as $element) {
            $params[] = $resource[$element] . ' = 0';
        }

        $sql = 'UPDATE %%PLANETS%% SET `b_hangar` = 0, `b_hangar_id` = "", ' . implode(", ", $params) . ' WHERE `id_owner` = :owner;';
        $db->update($sql, [':owner' => $USER['id']]);
    }

    public function disable_vmode(array &$USER, ?array &$PLANET = null): void
    {
        $db = $this->db;

        // Callers hold a transaction. Re-read the user under lock: a concurrent
        // request may already have ended vacation mode, and urlaubs_start = 0
        // would then shift every queue by TIMESTAMP.
        $sql = 'SELECT * FROM %%USERS%% WHERE `id` = :userID FOR UPDATE;';
        $u = $db->selectSingle($sql, [
            ':userID'   => $USER['id'],
        ]);
        if (empty($u) || $u['urlaubs_modus'] != 1) {
            return;
        }
        $USER = array_merge($USER, $u);
        $umode_start = $u['urlaubs_start'];
        $umode_delta = $this->state->runtime->timestamp - $umode_start;
        if ($USER['urlaubs_until'] <= $this->state->runtime->timestamp) {
            if (!empty($USER['b_tech']) && !empty($USER['b_tech_queue'])) {
                $CurrentQueue       = unserialize($USER['b_tech_queue']);
                foreach ($CurrentQueue as &$TechArray) {
                    $TechArray[3] += $umode_delta;
                }
                $USER['b_tech_queue'] = serialize($CurrentQueue);
                unset($CurrentQueue);
                $sql = 'UPDATE %%USERS%% SET
                    `b_tech` = `b_tech` + :umode_delta,
                    `b_tech_queue` = :b_tech_queue
                    WHERE `id` = :userID;';
                $db->update($sql, [
                    ':umode_delta' => $umode_delta,
                    ':userID'   => $USER['id'],
                    ':b_tech_queue' => $USER['b_tech_queue'],
                ]);
                $USER['b_tech'] = $USER['b_tech'] + $umode_delta;
            }
            if (isset($PLANET) && !empty($PLANET['b_building']) && !empty($PLANET['b_building_id'])) {
                $PLANET['b_building'] = $PLANET['b_building'] + $umode_delta;
                $CurrentQueue       = unserialize($PLANET['b_building_id']);
                foreach ($CurrentQueue as &$BuildArray) {
                    $BuildArray[3] += $umode_delta;
                }
                $PLANET['b_building_id'] = serialize($CurrentQueue);
                unset($CurrentQueue);
            }

            $sql = 'SELECT `id`, `b_building`, `b_building_id` FROM %%PLANETS%% WHERE `id_owner` = :userID AND `destruyed` = 0 FOR UPDATE;';
            $planets = $db->select($sql, [
                ':userID'   => $USER['id'],
            ]);

            foreach ($planets as $CPLANET) {
                if (!empty($CPLANET['b_building']) && !empty($CPLANET['b_building_id'])) {
                    $CPLANET['b_building'] = $CPLANET['b_building'] + $umode_delta;
                    $CurrentQueue = unserialize($CPLANET['b_building_id']);
                    foreach ($CurrentQueue as &$BuildArray) {
                        $BuildArray[3] += $umode_delta;
                    }
                    $CPLANET['b_building_id'] = serialize($CurrentQueue);
                    unset($CurrentQueue);
                }

                $sql = 'UPDATE %%PLANETS%% SET
                    `b_building` = :building,
                    `b_building_id` = :current_queue,
                    `last_update` = :timestamp,
                    `metal_mine_porcent` = "10",
                    `crystal_mine_porcent` = "10",
                    `deuterium_sintetizer_porcent` = "10",
                    `solar_plant_porcent` = "10",
                    `fusion_plant_porcent` = "10",
                    `solar_satelit_porcent` = "10"
                    WHERE `id` = :planetID;';
                $db->update($sql, [
                    ':planetID' => $CPLANET['id'],
                    ':building' => $CPLANET['b_building'],
                    ':current_queue' => $CPLANET['b_building_id'],
                    ':timestamp' => $this->state->runtime->timestamp,
                ]);

                $sql = 'UPDATE %%PLANET_WRECKFIELD%% SET
                    `created` = `created` + :umode_delta,
                    `repair_order_start` =`repair_order_start` + :umode_delta,
                    `repair_order_end` = `repair_order_end` + :umode_delta
                    WHERE `planetID` = :planetID;';
                $db->update($sql, [
                    ':umode_delta' => $umode_delta,
                    ':planetID' => $CPLANET['id'],
                ]);

                unset($CPLANET);
            }

            $sql = 'UPDATE %%USERS%% SET
                `urlaubs_modus` = 0,
                `urlaubs_until` = 0,
                `urlaubs_start` = 0
                WHERE `id` = :userID;';
            $db->update($sql, [':userID'   => $USER['id']]);

            $USER['urlaubs_modus'] = 0;
            $USER['urlaubs_until'] = 0;
            $USER['urlaubs_start'] = 0;

            $sql = "SELECT * FROM %%PLANETS%% WHERE id_owner = :id;";
            $PlanetsRAW = $db->select($sql, [
                ':id' => $USER['id'],
            ]);

            $PlanetRess = new ResourceUpdate($this->db, $this->configs, $this->state);
            if (isset($PLANET)) {
                $PLANET['last_update'] = $this->state->runtime->timestamp;
                $PLANET['eco_hash'] = '';
                [$USER, $PLANET] = $PlanetRess->CalcResource($USER, $PLANET, true);
                $this->playerUtilAchievements()->checkPlayerUtilAchievements33($USER['id']);
            }

            foreach ($PlanetsRAW as $CPLANET) {
                $CPLANET['eco_hash'] = '';
                [$USER, $CPLANET] = $PlanetRess->CalcResource($USER, $CPLANET, true);
            }
        }
    }

    public function enable_vmode(array &$USER, ?array &$PLANET, bool $byBan = false): void
    {
        $db = $this->db;

        $sql = 'UPDATE %%USERS%% SET `urlaubs_modus` = 1, `urlaubs_until` = :time, `urlaubs_start` = :startTime
            WHERE `id` = :userID';
        $db->update($sql, [
            ':userID'       => $USER['id'],
            ':time'         => $byBan ? 0 : ($this->state->runtime->timestamp + $this->configs->get()->vmode_min_time),
            ':startTime'    => $this->state->runtime->timestamp,
        ]);

        $sql = 'UPDATE %%PLANETS%% SET `energy_used` = 0, `energy` = 0, `metal_mine_porcent` = "0",
            `crystal_mine_porcent` = "0", `deuterium_sintetizer_porcent` = "0", `solar_plant_porcent` = "0",
            `fusion_plant_porcent` = "0", `solar_satelit_porcent` = "0", `metal_perhour` = 0,
            `crystal_perhour` = 0, `deuterium_perhour` = 0 WHERE `id_owner` = :userID;';
        $db->update($sql, [
            ':userID'   => $USER['id'],
        ]);

        $sql = 'SELECT `id`, `owner` FROM %%FLEETS%%
            WHERE `universe` = :universe
            AND `mission` = :fleet_mision
            AND `target_owner` = :id;';
        $FleetsRAW = $db->select($sql, [
            ':universe'     => $this->universe()->current(),
            ':fleet_mision' => MISSION_HOLD,
            ':id'           => $USER['id'],
        ]);
        foreach ($FleetsRAW as $Fleet) {
            $attacker['id'] = $Fleet['owner'];
            $this->fleetFunctions()->SendFleetBack($attacker, $Fleet['id']);
        }
        if (!empty($PLANET)) {
            $PLANET['energy_used'] = '0';
            $PLANET['energy'] = '0';
            $PLANET['metal_mine_porcent'] = '0';
            $PLANET['crystal_mine_porcent'] = '0';
            $PLANET['deuterium_sintetizer_porcent'] = '0';
            $PLANET['solar_plant_porcent'] = '0';
            $PLANET['fusion_plant_porcent'] = '0';
            $PLANET['solar_satelit_porcent'] = '0';
            $PLANET['metal_perhour'] = '0';
            $PLANET['crystal_perhour'] = '0';
            $PLANET['deuterium_perhour'] = '0';
            $this->playerUtilAchievements()->checkPlayerUtilAchievements31($USER['id']);
        }
    }

    public function player_colors(?array $USER = null): array
    {
        if (!isset($USER)) {
            return [
                'colorMission2friend' => '#ff00ff',

                'colorMission1Own' => '#66cc33',
                'colorMission2Own' => '#339966',
                'colorMission3Own' => '#5bf1c2',
                'colorMission4Own' => '#cf79de',
                'colorMission5Own' => '#80a0c0',
                'colorMission6Own' => '#ffcc66',
                'colorMission7Own' => '#c1c1c1',
                'colorMission7OwnReturn' => '#cf79de',
                'colorMission8Own' => '#ceff68',
                'colorMission9Own' => '#ffff99',
                'colorMission10Own' => '#ffcc66',
                'colorMission15Own' => '#5bf1c2',
                'colorMission16Own' => '#5bf1c2',
                'colorMission17Own' => '#5bf1c2',

                'colorMissionReturnOwn' => '#6e8eea',

                'colorMission1Foreign' => '#ff0000',
                'colorMission2Foreign' => '#aa0000',
                'colorMission3Foreign' => '#00ff00',
                'colorMission4Foreign' => '#ad57bc',
                'colorMission5Foreign' => '#3399cc',
                'colorMission6Foreign' => '#ff6600',
                'colorMission7Foreign' => '#00ff00',
                'colorMission8Foreign' => '#acdd46',
                'colorMission9Foreign' => '#dddd77',
                'colorMission10Foreign' => '#ff6600',
                'colorMission15Foreign' => '#39d0a0',
                'colorMission16Foreign' => '#39d0a0',
                'colorMission17Foreign' => '#39d0a0',

                'colorMissionReturnForeign' => '#6e8eea',

                'colorStaticTimer' => '#ffff00',
            ];
        }

        if (isset($USER['colors'])) {
            return $USER['colors'];
        }

        return [
            'colorMission2friend' => $USER['colorMission2friend'],

            'colorMission1Own' => $USER['colorMission1Own'],
            'colorMission2Own' => $USER['colorMission2Own'],
            'colorMission3Own' => $USER['colorMission3Own'],
            'colorMission4Own' => $USER['colorMission4Own'],
            'colorMission5Own' => $USER['colorMission5Own'],
            'colorMission6Own' => $USER['colorMission6Own'],
            'colorMission7Own' => $USER['colorMission7Own'],
            'colorMission7OwnReturn' => $USER['colorMission7OwnReturn'],
            'colorMission8Own' => $USER['colorMission8Own'],
            'colorMission9Own' => $USER['colorMission9Own'],
            'colorMission10Own' => $USER['colorMission10Own'],
            'colorMission15Own' => $USER['colorMission15Own'],
            'colorMission16Own' => $USER['colorMission16Own'],
            'colorMission17Own' => $USER['colorMission17Own'],

            'colorMissionReturnOwn' => $USER['colorMissionReturnOwn'],

            'colorMission1Foreign' => $USER['colorMission1Foreign'],
            'colorMission2Foreign' => $USER['colorMission2Foreign'],
            'colorMission3Foreign' => $USER['colorMission3Foreign'],
            'colorMission4Foreign' => $USER['colorMission4Foreign'],
            'colorMission5Foreign' => $USER['colorMission5Foreign'],
            'colorMission6Foreign' => $USER['colorMission6Foreign'],
            'colorMission7Foreign' => $USER['colorMission7Foreign'],
            'colorMission8Foreign' => $USER['colorMission8Foreign'],
            'colorMission9Foreign' => $USER['colorMission9Foreign'],
            'colorMission10Foreign' => $USER['colorMission10Foreign'],
            'colorMission15Foreign' => $USER['colorMission15Foreign'],
            'colorMission16Foreign' => $USER['colorMission16Foreign'],
            'colorMission17Foreign' => $USER['colorMission17Foreign'],

            'colorMissionReturnForeign' => $USER['colorMissionReturnForeign'],

            'colorStaticTimer' => $USER['colorStaticTimer'],
        ];
    }

    public function player_stb_settings(?array $USER = null): array
    {
        if (!isset($USER)) {
            return [
                'stb_small_ress'    => 500,
                'stb_med_ress'      => 3000,
                'stb_big_ress'      => 7000,
                'stb_small_time'    => 1,
                'stb_med_time'      => 2,
                'stb_big_time'      => 3,
                'stb_enabled'       => 0,
            ];
        }

        if (isset($USER['stb_settings'])) {
            return $USER['stb_settings'];
        }

        return [
            'stb_small_ress'    => $USER['stb_small_ress'],
            'stb_med_ress'      => $USER['stb_med_ress'],
            'stb_big_ress'      => $USER['stb_big_ress'],
            'stb_small_time'    => $USER['stb_small_time'],
            'stb_med_time'      => $USER['stb_med_time'],
            'stb_big_time'      => $USER['stb_big_time'],
            'stb_enabled'       => $USER['stb_enabled'],
        ];
    }

    public function player_signal_colors(?array $USER = null): array
    {
        if (!isset($USER)) {
            return [
                'colorPositive' => '#00ff00',
                'colorNegative' => '#ff0000',
                'colorNeutral' => '#ffd600',
            ];
        }

        if (isset($USER['signalColors'])) {
            return $USER['signalColors'];
        }

        return [
            'colorPositive' => $USER['colorPositive'],
            'colorNegative' => $USER['colorNegative'],
            'colorNeutral' => $USER['colorNeutral'],
        ];
    }

    public function get_buddy_status(int $userId, int $targetUserId): bool
    {
        $db = $this->db;

        $sql = 'SELECT id FROM %%BUDDY%% WHERE ((`sender` = :userID AND `owner` = :targetID) OR (`sender` = :targetID AND `owner` = :userID)) AND id NOT IN (SELECT id FROM %%BUDDY_REQUEST%%);';
        $buddy = $db->selectSingle($sql, [
            ':userID'       => $userId,
            ':targetID'     => $targetUserId,
        ]);
        if (empty($buddy)) {
            return false;
        }
        return true;
    }

    public function get_block_status(int $userId, int $targetUserId): array
    {
        $db = $this->db;

        $sql = 'SELECT `block_dm`, `block_trade` FROM %%USERS_BLOCKLIST%% WHERE `blocked_user` = :userID AND `blocking_user` = :targetID;';
        $blocked = $db->selectSingle($sql, [
            ':userID'       => $userId,
            ':targetID'     => $targetUserId,
        ]);
        if (empty($blocked)) {
            return [
                'block_dm' => 0,
                'block_trade' => 0,
            ];
        }
        return $blocked;
    }

    public function get_authlevel(int $userId): int
    {
        $db = $this->db;

        $sql = 'SELECT `authlevel` FROM %%USERS%% WHERE `id` = :userID;';
        $authlevel = $db->selectSingle($sql, [
            ':userID'       => $userId,
        ], 'authlevel');

        return $authlevel;
    }

    public function get_account_id(int $userId): int
    {
        $db = $this->db;

        $sql = 'SELECT `account_id` FROM %%ACCOUNTS_TO_USERS%% WHERE `user_id` = :userID;';
        $accountId = $db->selectSingle($sql, [
            ':userID'       => $userId,
        ], 'account_id');
        if ($accountId === false) {
            $accountId = 0;
        }
        return $accountId;
    }

    /**
     * Calculate and update honorpoint changes for 1 vs 1 fights
     *
     * honorable for both if:
     * Attacker points <= Target points AND (Attacker military points <= double Target military points OR Target is Bandit(Low 30%))
     * OR
     * Attacker points < Target points AND (Attacker military points < double Target military points OR < 10 military points difference)
     *
     * dishonorable for attacker if:
     * Attacker military points > double Target military points AND > 10 military points difference AND Target honor rank is better than Banditlord(Low 20%)
     *
     * neutral if:
     * Attacker is in the same alliance as the target
     * Attacker is a buddy of the target
     * Defender is inactive
     * any case not covered by the above
     *
     * @param int           $attacker               Id of attacker
     * @param int           $defender               ID of defender
     * @param int           $attackerUnitsLost      Units destroyed by the defender
     * @param int           $defenderUnitsLost      Units destroyed by the attacker
     */
    public function calculate_honorpoints_single(int $attacker, int $defender, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        $db = $this->db;

        $honorpointsResult = [
            'attackersH' => [],
            'defendersH' => [],
        ];

        $sql = 'SELECT u.`id`, u.`username`,  s.`total_points`, (s.`fleet_points` + s.`defs_points`) as military, u.`ally_id`, u.`onlinetime`
            FROM %%USERS%% u
            INNER JOIN %%STATPOINTS%% s ON s.id_owner = u.id
            WHERE id = :id AND s.stat_type = 1;';
        $attackerUser = $db->selectSingle($sql, [
            ':id' => $attacker,
        ]);
        $defenderUser = $db->selectSingle($sql, [
            ':id' => $defender,
        ]);

        if ($this->state->runtime->timestamp - INACTIVE > $defenderUser['onlinetime'] ||
            ($attackerUser['ally_id'] == $defenderUser['ally_id'] && $attackerUser['ally_id'] != 0)) {
            return $honorpointsResult;
        }

        $sql = "SELECT count(*) as anz 
            FROM %%BUDDY%% 
            WHERE (`sender` = :attacker AND `owner` = :defender) 
               OR (`sender` = :defender AND `owner` = :attacker);";
        $buddy = $db->selectSingle($sql, [
            ':attacker' => $attacker,
            ':defender' => $defender,
        ], 'anz');

        if ($buddy > 0) {
            return $honorpointsResult;
        }

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $attackerUser['honorpoints'] = $statsHandler->getValue($attacker, AdvancedStatsEnum::HONORPOINTS);
        $defenderUser['honorpoints'] = $statsHandler->getValue($defender, AdvancedStatsEnum::HONORPOINTS);

        $honorDataTop = $this->get_honor_percent("low");

        $defenderIsBandit = $defenderUser['honorpoints'] <= $honorDataTop['top30']['honorpoints'] &&
            $defenderUser['honorpoints'] <= -500;

        $honorableForBoth = (
            $attackerUser['total_points'] <= $defenderUser['total_points'] &&
            (
                $attackerUser['military'] <= ($defenderUser['military'] * 2) ||
                $defenderIsBandit
            )
        ) || (
            $attackerUser['total_points'] < $defenderUser['total_points'] &&
            (
                $attackerUser['military'] <= ($defenderUser['military'] * 2) ||
                ($attackerUser['military'] - $defenderUser['military']) <= 10
            )
        );

        $dishonorableForAttacker = (
            $attackerUser['military'] > ($defenderUser['military'] * 2) &&
            ($attackerUser['military'] - $defenderUser['military']) > 10 &&
            $defenderUser['honorpoints'] >= $honorDataTop['top20']['honorpoints']
        );

        $attackerHonorpointsChange = 0;
        $defenderHonorpointsChange = 0;

        if ($honorableForBoth) {
            $attackerHonorpointsChange = floor(pow($defenderUnitsLost, 0.9) / 1000);
            $defenderHonorpointsChange = floor(pow($attackerUnitsLost, 0.9) / 1000);

            $statsHandler->increment($attacker, AdvancedStatsEnum::HONORABLE_FIGHTS, 1);
            $statsHandler->increment($attacker, AdvancedStatsEnum::HONORPOINTS, $attackerHonorpointsChange);

            $statsHandler->increment($defender, AdvancedStatsEnum::HONORABLE_FIGHTS, 1);
            $statsHandler->increment($defender, AdvancedStatsEnum::HONORPOINTS, $defenderHonorpointsChange);
        } elseif ($dishonorableForAttacker) {
            $attackerHonorpointsChange = 0 - floor(pow($defenderUnitsLost, 0.9) / 1000);
            $statsHandler->increment($attacker, AdvancedStatsEnum::DISHONORABLE_FIGHTS, 1);
            $statsHandler->increment($attacker, AdvancedStatsEnum::HONORPOINTS, $attackerHonorpointsChange);
        } else {
            return $honorpointsResult;
        }

        $honorpointsResult['attackersH'][$attackerUser['username']] = $attackerHonorpointsChange;
        $honorpointsResult['defendersH'][$defenderUser['username']] = $defenderHonorpointsChange;

        return $honorpointsResult;
    }

    /**
     * Calculate and update the change in honorpoints for multiple attackers and defenders(ACS)
     * Points get distributed proportional to the ships send(points)
     * Player needs to add more than 1% of the total points to get honorpoints
     *
     * honorable for attackers if:
     * sum of attacker military points <= double of the sum of defender military points OR one of the defenders is Bandit(Low 30%)
     *
     * honorable for defenders if:
     * sum of defender military points <= double of the sum of attacker military points OR one of the attackers is Bandit(Low 30%)
     *
     * @param array $attackers
     * @param array $defenders
     * @param int $attackerUnitsLost
     * @param int $defenderUnitsLost
     */
    public function calculate_honorpoints_multi(array $attackers, array $defenders, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        $pricelist = & $this->state->pricelist;
        $db = $this->db;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $honorDataTop = $this->get_honor_percent("low");
        $attackerHasBandit = false;
        $defenderHasBandit = false;
        $sumMilitaryPointsAttackers = 0;
        $sumMilitaryPointsDefenders = 0;

        $playerData = [];

        $sql = 'SELECT u.id, (`fleet_points` + `defs_points`) as military
            FROM %%USERS%% u
            INNER JOIN %%STATPOINTS%% s ON s.id_owner = u.id
            WHERE u.`id` = :id AND s.stat_type = 1;';

        $attackerFullpoints = 0;
        $defenderFullpoints = 0;

        // Gather data for attackers
        foreach ($attackers as $FleetID => $player) {
            $playerID = $player['player']['id'];

            if (!isset($playerData[$playerID])) {
                $playerData[$playerID] = [
                    'side' => 'attacker',
                    'username' => $player['player']['username'],
                    'fightpoints' => 0,
                ];

                $sumMilitaryPointsAttackers += $db->selectSingle($sql, [
                    ':id' => $playerID,
                ], 'military');

                if (!$attackerHasBandit) {
                    $honorpoints = $statsHandler->getValue($playerID, AdvancedStatsEnum::HONORPOINTS);
                    $attackerHasBandit = $honorpoints <= $honorDataTop['top30']['honorpoints'] &&
                        $honorpoints <= -500;
                }
            }

            foreach ($player['unit'] as $ShipID => $Amount) {
                $fullCost = $pricelist[$ShipID]['cost'][901] + $pricelist[$ShipID]['cost'][902] + $pricelist[$ShipID]['cost'][903];
                $playerData[$playerID]['fightpoints'] += $Amount * $fullCost;
                $attackerFullpoints += $Amount * $fullCost;
            }
        }

        // Gather data for defenders
        foreach ($defenders as $FleetID => $player) {
            $playerID = $player['player']['id'];

            if (!isset($playerData[$playerID])) {
                $playerData[$playerID] = [
                    'side' => 'defender',
                    'username' => $player['player']['username'],
                    'fightpoints' => 0,
                ];

                $sumMilitaryPointsDefenders += $db->selectSingle($sql, [
                    ':id' => $playerID,
                ], 'military');

                if (!$defenderHasBandit) {
                    $honorpoints = $statsHandler->getValue($playerID, AdvancedStatsEnum::HONORPOINTS);
                    $defenderHasBandit = $honorpoints <= $honorDataTop['top30']['honorpoints'] &&
                        $honorpoints <= -500;
                }
            }

            foreach ($player['unit'] as $ShipID => $Amount) {
                $fullCost = $pricelist[$ShipID]['cost'][901] + $pricelist[$ShipID]['cost'][902] + $pricelist[$ShipID]['cost'][903];
                $playerData[$playerID]['fightpoints'] += $Amount * $fullCost;
                $defenderFullpoints += $Amount * $fullCost;
            }
        }

        // Calculate honorpoint changes for attackers
        if ($sumMilitaryPointsAttackers <= ($sumMilitaryPointsDefenders * 2) || $defenderHasBandit) {
            $attackerHonorpointsChange = floor(pow($defenderUnitsLost, 0.9) / 1000);
        } else {
            $attackerHonorpointsChange = 0 - floor(pow($defenderUnitsLost, 0.9) / 1000);
        }

        // Calculate honorpoint changes for defenders
        if ($sumMilitaryPointsDefenders <= ($sumMilitaryPointsAttackers * 2) || $attackerHasBandit) {
            $defenderHonorpointsChange = floor(pow($attackerUnitsLost, 0.9) / 1000);
        } else {
            $defenderHonorpointsChange = 0 - floor(pow($attackerUnitsLost, 0.9) / 1000);
        }

        $result = [
            'attackersH' => [],
            'defendersH' => [],
        ];
        // Distribute honorpoints to players
        foreach ($playerData as $playerID => $data) {
            $fullpoints = 0;
            $pointsChange = 0;
            if ($data['side'] == 'attacker') {
                $fullpoints = $attackerFullpoints;
                $pointsChange = $attackerHonorpointsChange;

                if ($attackerHonorpointsChange > 0) {
                    $statsHandler->increment($playerID, AdvancedStatsEnum::HONORABLE_FIGHTS, 1);
                } elseif ($attackerHonorpointsChange < 0) {
                    $statsHandler->increment($playerID, AdvancedStatsEnum::DISHONORABLE_FIGHTS, 1);
                }
            } else {
                $fullpoints = $defenderFullpoints;
                $pointsChange = $defenderHonorpointsChange;

                if ($defenderHonorpointsChange > 0) {
                    $statsHandler->increment($playerID, AdvancedStatsEnum::HONORABLE_FIGHTS, 1);
                } elseif ($defenderHonorpointsChange < 0) {
                    $statsHandler->increment($playerID, AdvancedStatsEnum::DISHONORABLE_FIGHTS, 1);
                }
            }

            if (!isset($data['fightpoints']) || $fullpoints == 0 || $data['fightpoints'] / $fullpoints <= 0.01) {
                $result[$data['side'] . 'sH'][$data['username']] = 0;
                continue;
            }

            $change = $pointsChange * ($data['fightpoints'] / $fullpoints);
            $statsHandler->increment($playerID, AdvancedStatsEnum::HONORPOINTS, $change);
            $result[$data['side'] . 'sH'][$data['username']] = $change;
        }

        return $result;
    }

    /**
     * Compare the number of honorable and dishonorable fights of a player with the reference data
     * true if the player has more honorable fights or the same number of honorable fights and less dishonorable fights
     * @param array $userFights
     * @param array $referenceFights
     * @return bool
     */
    public function compare_honor_fights(array $userFights, array $referenceFights): bool
    {
        if ($userFights['honorable_fights'] > $referenceFights['honorable_fights']) {
            return true;
        } elseif ($userFights['honorable_fights'] == $referenceFights['honorable_fights']) {
            if ($userFights['dishonorable_fights'] < $referenceFights['dishonorable_fights']) {
                return true;
            }
        }
        return false;
    }

    /**
     * Retrieve the honorpoints and the number of honorable and dishonorable fights of a player
     * @param int $userId
     * @return array $honordata
     */
    public function get_honor_data(int $userId): array
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        return $statsHandler->getMultipleStats($userId, [
            AdvancedStatsEnum::HONORPOINTS,
            AdvancedStatsEnum::HONORABLE_FIGHTS,
            AdvancedStatsEnum::DISHONORABLE_FIGHTS,
        ]);
    }

    /**
     * Calculate good effect of honorpoints (decrease deut costs for attacks on players with high honorpoints)
     * can be 0.80(20%), 0.88(12%), 0.95(5%) or 1(0%) decrease
     * @param int $userId
    * @return float $factor
     */
    public function calculate_honorpoints_boost(int $userId): float
    {
        $factor = 1.0;
        $honordata = $this->get_honor_data($userId);

        if ($honordata['honorpoints'] >= 500 && $this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
            $honorDataTop = $this->get_honor_percent("top");
            if ($honordata['honorpoints'] > $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] >= 15000) {
                $factor = 0.80;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] >= 2500) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top10']) && $honordata['honorpoints'] >= 15000 ? 0.80 : 0.88;
            } elseif ($honordata['honorpoints'] > $honorDataTop['top20']['honorpoints'] && $honordata['honorpoints'] >= 2500) {
                $factor = 0.88;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top20']['honorpoints']) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top20']) && $honordata['honorpoints'] >= 2500 ? 0.88 : 0.95;
            } elseif ($honordata['honorpoints'] > $honorDataTop['top30']['honorpoints']) {
                $factor = 0.95;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top30']['honorpoints']) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top30']) ? 0.95 : 1;
            }
        }
        return $factor;
    }

    /**
     * Calculate bad effect of honorpoints (increase loot for attacks on players with low honorpoints)
     * can be 1(50%), 1.2(60%), 1.6(80%) or 2(100%) increase
     * @param int $userId
     * @return float $factor
     */
    public function calculate_honorpoints_decrease(int $userId): float
    {
        $factor = 1;
        $honordata = $this->get_honor_data($userId);

        if ($honordata['honorpoints'] <= -500 && $this->viewHelpers()->isModuleAvailable(MODULE_HONORPOINTS)) {
            $honorDataTop = $this->get_honor_percent("low");
            if ($honordata['honorpoints'] < $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] <= -15000) {
                $factor = 2;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top10']['honorpoints'] && $honordata['honorpoints'] <= -2500) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top10']) && $honordata['honorpoints'] > -15000 ? 1.6 : 2;
            } elseif ($honordata['honorpoints'] < $honorDataTop['top20']['honorpoints'] && $honordata['honorpoints'] <= -2500) {
                $factor = 1.6;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top20']['honorpoints'] && $honordata['honorpoints'] <= -500) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top20']) && $honordata['honorpoints'] > -2500 ? 1.2 : 1.6;
            } elseif ($honordata['honorpoints'] < $honorDataTop['top30']['honorpoints'] && $honordata['honorpoints'] <= -500) {
                $factor = 1.2;
            } elseif ($honordata['honorpoints'] == $honorDataTop['top30']['honorpoints']) {
                $factor = $this->compare_honor_fights($honordata, $honorDataTop['top30']) && $honordata['honorpoints'] > -500 ? 1 : 1.2;
            }
        }
        return $factor;
    }

    /**
     * Calculate the border dataset for the top or low 10%, 20% and 30% of the universe
     *
     * @param string $kind("top" or "low")
     * @return array
     */
    public function get_honor_percent(string $kind = "top"): array
    {
        $db = $this->db;
        $sql = 'SELECT count(*) as count FROM %%USERS%% WHERE universe = :universe;';
        $userAmount = $db->selectSingle($sql, [
            ':universe' => $this->universe()->current(),
        ], 'count');

        $limTop10 = ceil(($userAmount - 1) * (1 - 0.90));
        $limTop20 = ceil(($userAmount - 1) * (1 - 0.80));
        $limTop30 = ceil(($userAmount - 1) * (1 - 0.70));
        //top 10%   - 20% deut rabatt
        //top 20%   - 12% deut rabatt
        //top 30%   - 5% deut rabatt
        //mid 40%   - neutral (0% rabatt, 50% loot)
        //low 30%   - 60% loot
        //low 20%   - 80% loot
        //low 10%   - 100% loot

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $honorDataTop10 = $statsHandler->getHonorList($this->universe()->current(), $limTop10, $kind);
        $honorDataTop20 = $statsHandler->getHonorList($this->universe()->current(), $limTop20, $kind);
        $honorDataTop30 = $statsHandler->getHonorList($this->universe()->current(), $limTop30, $kind);

        return [
            "top10" => end($honorDataTop10),
            "top20" => end($honorDataTop20),
            "top30" => end($honorDataTop30),
        ];
    }

    /**
     * Calculates, if enabled via config, the cost reduction factor based on the
     * percentage points difference to the top player of the universe. Points are
     * weighted: 1 x buildings + .8 x research + .6 x fleet
     *
     * - Calculation: factor = 10 * pow(0.9747, (userPoints/maxPoints * 100)) => (1 - 10)
     *                factor = (1-(1-0,5)/(10-1)*factor-1) => (1 - 0,5)
     * - Max factor: 0,5
     * - Min factor: 1
     * - Min percentage difference for boost: 10%
     * - Boost reduces build cost and therefore also build speed
     *
     * @param array $USER
     * @return float
     */
    public function getCatchUpFactor(array $USER): float
    {
        $factor = 1;
        $config = $this->configs->get($USER['universe']);

        if ($config->catch_up && $USER['onlinetime'] > $this->state->runtime->timestamp - INACTIVE) {
            $db = $this->db;

            $sql = 'SELECT (`build_points` * 1 + `tech_points` * 0.8 + `fleet_points` * 0.6) as points
                FROM %%STATPOINTS%%
                WHERE `id_owner` = :userID AND `stat_type` = 1;';
            $userPoints = $db->selectSingle($sql, [
                ':userID' => $USER['id'],
            ], 'points');
            $userPoints = max($userPoints, 1);

            $sql = 'SELECT max(`build_points` * 1 + `tech_points` * 0.8 + `fleet_points` * 0.6) AS max
                FROM %%STATPOINTS%%
                WHERE `universe` = :universe AND `stat_type` = 1;';
            $maxPoints = $db->selectSingle($sql, [
                ':universe' => $USER['universe'],
            ], 'max');
            $maxPoints = max($maxPoints, 1);

            $percentageOfMaxPoints = $userPoints / $maxPoints * 100;
            $maxBoost = 10;
            $reductionFactor = 0.9747;
            $factor = $maxBoost * pow($reductionFactor, $percentageOfMaxPoints);
            $factor = max($factor, 1);

            $maxFactor = $config->catchup_percent / 100;
            $factor = (1 - (1 - $maxFactor) / ($maxBoost - 1) * ($factor - 1));
        }

        return $factor;
    }

    public function getCatchUpFactorForElement(float $factor, int $elementId, int $currentLevel): float
    {
        if ($factor == 1.0) {
            return 1.0;
        }

        $db = $this->db;

        $sql = 'SELECT `level` FROM %%RECORDS%% WHERE `elementID` = :elementId;';
        $maxLevel = $db->selectSingle($sql, [
            ':elementId' => $elementId,
        ], 'level');

        $diff = $maxLevel - $currentLevel;
        return match (true) {
            $diff <= 0 => 1.0,
            $diff === 1 => $factor + (1 - $factor) * 2 / 3.0,
            $diff === 2 => $factor + (1 - $factor) * 1 / 3.0,
            default => $factor,
        };
    }

    public function spyTechAllowed(int $necessaryLevel, int $PlayerID, array $USER): bool
    {
        $allowed = true;
        if ($this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS) && $PlayerID != $USER['id'] &&
            $USER['authlevel'] == 0 && $USER['spy_tech'] < $necessaryLevel) {
            $allowed = false;
        }
        return $allowed;
    }

    public function getChartPoints(int $targetUserId, array $USER, int $timespan = TIME_1_WEEK): array
    {
        $db = $this->db;

        $sql = "SELECT * FROM %%STATPOINTS_HISTORY%%
                WHERE `id_owner` = :userId AND `timestamp` < :timestamp AND `timestamp` > :timespan
                ORDER BY `timestamp` DESC;";
        $statpoints = $db->select($sql, [
            ':userId' => $targetUserId,
            ':timestamp' => ($targetUserId == $USER['id'] ? $this->state->runtime->timestamp : $this->state->runtime->timestamp - TIME_24_HOURS),
            ':timespan' => $this->state->runtime->timestamp - $timespan,
        ]);
        $statpoints = array_reverse($statpoints);

        $result = [];
        $result['userId'] = $targetUserId;
        $result['points'] = [
            'time' => [],
            'hoursAgo' => [],
            'total' => [],
            'build' => [],
            'tech' => [],
            'fleet' => [],
            'defence' => [],
            'ownTotal' => [],
        ];
        $result['bars'] = [
            'scoreRelative' => [],
            'scoreAbsolute' => [],
        ];

        // the viewer's own rows at the same timestamps, one query (the first row per timestamp, lowest stat_type)
        $ownByTimestamp = [];
        if (!empty($statpoints)) {
            $timestamps = array_map('intval', array_column($statpoints, 'timestamp'));
            $sql = "SELECT * FROM %%STATPOINTS_HISTORY%%
                WHERE `id_owner` = :userId AND `timestamp` IN (" . implode(', ', $timestamps) . ")
                ORDER BY `stat_type` ASC;";
            foreach ($db->select($sql, [':userId' => $USER['id']]) as $ownRow) {
                $ownByTimestamp[$ownRow['timestamp']] ??= $ownRow;
            }
        }

        $LNG = & $this->state->LNG;
        foreach ($statpoints as $row) {
            $result['points']['time'][] = $this->viewHelpers()->formatDate("M d Y H:i", $row['timestamp'], $USER['timezone']);
            $result['points']['hoursAgo'][] = $this->formatChartHoursAgo((int) $row['timestamp'], $LNG);
            $result['points']['tech'][] = ($this->spyTechAllowed(4, $targetUserId, $USER) ? $row['tech_points'] : 0);
            $result['points']['build'][] = ($this->spyTechAllowed(2, $targetUserId, $USER) ? $row['build_points'] : 0);
            $result['points']['defence'][] = ($this->spyTechAllowed(6, $targetUserId, $USER) ? $row['defs_points'] : 0);
            $result['points']['fleet'][] = ($this->spyTechAllowed(6, $targetUserId, $USER) ? $row['fleet_points'] : 0);
            $result['points']['total'][] = $row['total_points'];

            $ownStatpoints = $ownByTimestamp[$row['timestamp']] ?? null;
            $result['points']['ownTotal'][] = $ownStatpoints['total_points'] ?? 0;
        }

        for ($i = 1; $i < count($statpoints); $i++) {
            $previous = (int) ($statpoints[$i - 1]['total_points'] ?? 0);
            $current = (int) ($statpoints[$i]['total_points'] ?? 0);
            $absolute = $current - $previous;
            if ($previous > 0 && $current == 0) {
                $relative = -1;
            } elseif ($previous == 0) {
                $relative = $current > 0 ? 1 : 0;
            } else {
                $relative = $absolute / $previous;
            }
            $result['bars']['scoreRelative'][] = round($relative * 100, 2);
            $result['bars']['scoreAbsolute'][] = $absolute;
        }

        return $result;
    }

    private function formatChartHoursAgo(int $timestamp, $LNG): string
    {
        return $this->formatChartAgo($timestamp, $LNG, false);
    }

    public function getAllianceChartPoints(int $allyId, array $USER, int $timespan = TIME_1_MONTH * 12): array
    {
        $db = $this->db;

        $sql = "SELECT 
                s.`id_owner`,
                u.`username`,
                s.`timestamp`, 
                s.`total_points`
            FROM %%STATPOINTS_HISTORY%% s
            JOIN %%USERS%% u ON s.`id_owner` = u.`id`
            WHERE s.`id_ally` = :allyId
            AND s.`timestamp` BETWEEN :startTime AND :now
            ORDER BY s.`timestamp`
        ";
        $rows = $db->select($sql, [
            ':allyId' => $allyId,
            ':startTime' => $this->state->runtime->timestamp - $timespan,
            ':now' => $this->state->runtime->timestamp - TIME_24_HOURS,
        ]);

        $usersData   = [];
        $allTimestamps = [];

        foreach ($rows as $r) {
            $allTimestamps[$r['timestamp']] = true;
        }

        $timestamps = array_keys($allTimestamps);
        sort($timestamps, SORT_NUMERIC);

        $LNG = & $this->state->LNG;
        $oldest = empty($timestamps) ? $this->state->runtime->timestamp : (int) $timestamps[0];
        $useDays = ($this->state->runtime->timestamp - $oldest) >= TIME_24_HOURS * 2;
        $timesFormated = [];
        $timesAgo = [];
        foreach ($timestamps as $ts) {
            $timesFormated[] = $this->viewHelpers()->formatDate("M d Y H:i", $ts, $USER['timezone']);
            $timesAgo[] = $this->formatChartAgo((int) $ts, $LNG, $useDays);
        }

        foreach ($rows as $r) {
            $uid = $r['id_owner'];
            if (!isset($usersData[$uid])) {
                $usersData[$uid] = [
                    'label'       => $r['username'],
                    'data'        => array_fill(0, count($timestamps), null),
                    'borderColor' => null,
                    'borderWidth' => 1,
                    'radius'      => 1,
                ];
            }

            $idx = array_search($r['timestamp'], $timestamps, true);
            $usersData[$uid]['data'][$idx] = (int)$r['total_points'];
        }

        $colors = ['red','orange','yellow','green','blue','indigo','white','lightgreen','lightblue'];
        $i = 0;
        foreach ($usersData as &$u) {
            $u['borderColor'] = $colors[$i % count($colors)];
            $i++;
        }
        unset($u);

        return [
            'timesFormated' => $timesFormated,
            'timesAgo'      => $timesAgo,
            'dataSets'      => array_values($usersData),
        ];
    }

    private function formatChartAgo(int $timestamp, $LNG, bool $useDays): string
    {
        $elapsed = max(0, $this->state->runtime->timestamp - $timestamp);
        if ($useDays) {
            return intdiv($elapsed, TIME_24_HOURS) . $LNG['short_day'];
        }
        return intdiv($elapsed, 3600) . $LNG['short_hour'];
    }

    public function getPlayerById(int $playerId): ?array
    {
        $db = $this->db;

        $sql = 'SELECT u.*, s.*
            FROM %%USERS%% u
            INNER JOIN %%STATPOINTS%% s ON s.`id_owner` = u.`id`
            WHERE u.`id` = :playerId AND s.`stat_type` = 1;';
        return $db->selectSingle($sql, [
            ':playerId' => $playerId,
        ]);
    }

    public function getUniverse(int $userId): int
    {
        $db = $this->db;

        $sql = 'SELECT `universe` FROM %%USERS%% WHERE `id` = :userID;';
        return (int)$db->selectSingle($sql, [
            ':userID' => $userId,
        ], 'universe');
    }

    // game rules formerly in functions.php (the globals remain as wrappers)

    public function getLanguage($language = null, $userID = null)
    {
        if (is_null($language) && !is_null($userID)) {
            $language = $this->db->selectSingle('SELECT lang FROM %%USERS_SETTINGS%% WHERE user_id = :id;', [
                ':id' => $userID
            ])['lang'];
        }

        $LNG = new Language($this->configs, $language);
        $LNG->includeData(['L18N', 'FLEET', 'TECH', 'CUSTOM', 'INGAME']);
        return $LNG;
    }

    public function getPlanets($USER)
    {
        if (isset($USER['PLANETS'])) {
            return $USER['PLANETS'];
        }

        $order = $USER['planet_sort_order'] == 1 ? "DESC" : "ASC";

        $sql = "SELECT id, name, galaxy, system, planet, planet_type, image, planet_style, b_building, b_building_id, watch_id
    			FROM %%PLANETS%% WHERE id_owner = :userId AND destruyed = :destruyed ORDER BY ";

        switch ($USER['planet_sort']) {
            case 0:
                $sql .= 'id ' . $order;
                break;
            case 1:
                $sql .= 'galaxy ' . $order . ', system ' . $order . ', planet ' . $order . ', planet_type ' . $order;
                break;
            case 2:
                $sql .= 'name ' . $order;
                break;
        }

        $planetsResult = $this->db->select($sql, [
            ':userId' => $USER['id'],
            ':destruyed' => 0
        ]);

        $planetsList = [];

        foreach ($planetsResult as $planetRow) {
            $planetsList[$planetRow['id']] = $planetRow;
        }

        return $planetsList;
    }

    public function getMainPlanet(array $user): array
    {
        /**
         * @var Database
         */
        $db = $this->db;

        $sql = "SELECT * FROM %%PLANETS%% 
            WHERE `universe` = :universe AND `galaxy` = :galaxy AND `system` = :system AND `planet` = :planet
            AND `planet_type` = 1;";
        $planet = $db->selectSingle($sql, [
            ':universe' => $user['universe'],
            ':galaxy' => $user['galaxy'],
            ':system' => $user['system'],
            ':planet' => $user['planet'],
        ]);
        return is_array($planet) ? $planet : [];
    }

    public function calculateMaxPlanetFields($planet)
    {
        $resource = & $this->state->resource;
        return $planet['field_max'] + floor($planet[$resource[33]] * FIELDS_BY_TERRAFORMER) + ($planet[$resource[41]]
            * FIELDS_BY_MOONBASIS_LEVEL);
    }

    /** status classes of a player row (banned, vacation, inactive, noob, strong) for lists and the galaxy */
    public function userStatus(array $data, bool|array $noob_protection = false): array
    {
        $Array = [];

        if (isset($data['banaday']) && $data['banaday'] > $this->state->runtime->timestamp) {
            $Array[] = 'banned';
        }

        if (isset($data['urlaubs_modus']) && $data['urlaubs_modus'] == 1) {
            $Array[] = 'vacation';
        }

        if (isset($data['onlinetime']) && $data['onlinetime'] < $this->state->runtime->timestamp - INACTIVE_LONG) {
            $Array[] = 'longinactive';
        }

        if (isset($data['onlinetime']) && $data['onlinetime'] < $this->state->runtime->timestamp - INACTIVE) {
            $Array[] = 'inactive';
        }

        if ($noob_protection && $noob_protection['NoobPlayer']) {
            $Array[] = 'noob';
        }

        if ($noob_protection && $noob_protection['StrongPlayer']) {
            $Array[] = 'strong';
        }

        return $Array;
    }

    public function checkNoobProtection($OwnerPlayer, $TargetPlayer)
    {
        $config = $this->configs->get();
        if (
            $config->noob_protection == 0
            || $config->noob_protection_time == 0
            || $config->noob_protection_multi == 0
            || $TargetPlayer['banaday'] > $this->state->runtime->timestamp
            || ($TargetPlayer['onlinetime'] < $this->state->runtime->timestamp - INACTIVE && $config->noob_inactives == 0)
        ) {
            return ['NoobPlayer' => false, 'StrongPlayer' => false];
        }

        $rebuildProtectionTarget = false;
        if ($config->rebuild_protection == 1 && $OwnerPlayer['id'] != $TargetPlayer['id'] && $TargetPlayer['rebuild_protection'] > 0) {
            if (($TargetPlayer['rebuild_protection'] + TIME_24_HOURS * 2) < $this->state->runtime->timestamp) {
                $sql = "UPDATE %%USERS%% SET `rebuild_protection` = 0, `rebuild_protection_attackers` = ''
                    WHERE `id` = :userID;";
                $this->db->update($sql, [':userID' => $TargetPlayer['id']]);
            } else {
                $attackers = json_decode($TargetPlayer['rebuild_protection_attackers'] == null ? '' : $TargetPlayer['rebuild_protection_attackers']);
                if (is_array($attackers) && in_array($OwnerPlayer['id'], $attackers)) {
                    $rebuildProtectionTarget = ($TargetPlayer['rebuild_protection'] + TIME_6_HOURS) <= $this->state->runtime->timestamp;
                } else {
                    $rebuildProtectionTarget = ($TargetPlayer['rebuild_protection'] + (TIME_6_HOURS / 2)) <= $this->state->runtime->timestamp;
                }
            }
        }

        $rebuildProtectionOwner = false;
        if ($config->rebuild_protection == 1 && $OwnerPlayer['id'] != $TargetPlayer['id'] && $OwnerPlayer['rebuild_protection'] > 0) {
            if (($OwnerPlayer['rebuild_protection'] + TIME_24_HOURS * 2) < $this->state->runtime->timestamp) {
                $sql = "UPDATE %%USERS%% SET `rebuild_protection` = 0, `rebuild_protection_attackers` = ''
                    WHERE `id` = :userID;";
                $this->db->update($sql, [':userID' => $OwnerPlayer['id']]);
            } else {
                $attackers = json_decode($OwnerPlayer['rebuild_protection_attackers'] == null ? '' : $OwnerPlayer['rebuild_protection_attackers']);
                if (is_array($attackers) && in_array($TargetPlayer['id'], $attackers)) {
                    $rebuildProtectionOwner = ($OwnerPlayer['rebuild_protection'] + TIME_6_HOURS) <= $this->state->runtime->timestamp;
                } else {
                    $rebuildProtectionOwner = ($OwnerPlayer['rebuild_protection'] + (TIME_6_HOURS / 2)) <= $this->state->runtime->timestamp;
                }
            }
        }

        /* WAHR:
            Wenn Zielspieler im Rebuild Schutz ist ODER
            Wenn Zielspieler inaktiv ist UND Inaktivschutz aktiviert ist ODER weniger als X Puntke hat UND
            Wenn Spieler mehr als das Y-fache der Zielspieler-Punkte hat.
        */
        $noob = $rebuildProtectionTarget || (
                (($TargetPlayer['onlinetime'] < $this->state->runtime->timestamp - INACTIVE && $config->noob_inactives == 1) ||
                    ($TargetPlayer['total_points'] <= $config->noob_protection_time)) && // Default: 5.000
                ($OwnerPlayer['total_points'] > $TargetPlayer['total_points'] * $config->noob_protection_multi)
            );
        /* WAHR:
            Wenn Zielspieler aktiv ist UND
            Wenn Spieler im Rebuild Schutz ist ODER
            Wenn Spieler weniger als X Punkte hat UND
            Zielspieler weniger als das Y-fache der Spieler-Punkte hat
        */
        $strong = $TargetPlayer['onlinetime'] > $this->state->runtime->timestamp - INACTIVE && (
                $rebuildProtectionOwner ||
                (($OwnerPlayer['total_points'] < $config->noob_protection_time) && // Default: 5.000
                    ($OwnerPlayer['total_points'] * $config->noob_protection_multi < $TargetPlayer['total_points']))
            );

        // the outlaw state only matters when the universe lets it lift the protection
        if ($config->noob_protection_outlaw) {
            $statsHandler = new \App\Game\AdvancedStats\AdvancedStatsHandler($this->db, $this->configs, $this->state);
            $outlaw = false;
            $outlawActivations = $statsHandler->getValue($TargetPlayer['id'], \App\Game\AdvancedStats\AdvancedStatsEnum::OUTLAW_ACTIVATIONS);
            if ($outlawActivations > 1) {
                $outlaw = $TargetPlayer['outlaw_time'] > $this->state->runtime->timestamp - 7 * TIME_24_HOURS;
            } elseif ($outlawActivations == 1) {
                $outlaw = $TargetPlayer['outlaw_time'] > $this->state->runtime->timestamp - 2 * TIME_24_HOURS;
            }

            if ($outlaw) {
                $noob = false;
                $strong = false;
            }
        }

        return [
            'NoobPlayer' => $noob,
            'StrongPlayer' => $strong && !$noob,
        ];
    }
}
