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

namespace App\Controller\Admin;

use App\Game\Core\AccountUtil;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;

/** Creates accounts, players, moons, planets and test data. */
#[\App\Routing\GamePage('admin.php', 'creator')]
#[\App\Routing\GamePage('admin.php', 'creator', uniPrefix: true)]
class CreatorController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;

        if (empty($this->state->requestQuery['mode'])) {
            $this->state->requestQuery['mode'] = $this->state->requestQuery['page'];
        }
        switch ($this->state->requestQuery['mode']) {
            case 'account':
                $LNG->includeData(['PUBLIC']);
                if ($this->state->requestPost) {
                    $accountName = $this->http()->_GP('name', '', UTF8_SUPPORT);
                    $accountPass = $this->http()->_GP('password', '');
                    $accountPass2 = $this->http()->_GP('password2', '');
                    $accountMail = $this->http()->_GP('email', '');
                    $accountMail2 = $this->http()->_GP('email2', '');
                    $authlevel = $this->http()->_GP('authlevel', 0);
                    $Language = $this->http()->_GP('lang', '');
                    $accountID = $this->http()->_GP('accountID', 0);

                    $db = $this->db;
                    $sql = "SELECT COUNT(*) as count FROM %%ACCOUNTS%% WHERE accountname = :accountname;";
                    $ExistsUser = $db->selectSingle($sql, [':accountname' => $accountName], 'count');

                    $sql = "SELECT COUNT(*) as count FROM %%ACCOUNTS%% WHERE email = :email OR email_2 = :email;";
                    $ExistsMails = $db->selectSingle($sql, [':email' => $accountMail], 'count');

                    $errors = "";

                    $config = $this->configs->get($this->universe()->getEmulated());

                    if ($ExistsMails != 0) {
                        $errors .= $LNG['mail_already_exists'];
                    }
                    if (empty($accountName)) {
                        $errors .= $LNG['registerErrorUsernameEmpty'];
                        $errors .= "<br>";
                    }

                    if (strlen($accountPass) < 6) {
                        $errors .= sprintf($LNG['registerErrorPasswordLength'], 6);
                        $errors .= "<br>";
                    }

                    if ($accountPass != $accountPass2) {
                        $errors .= $LNG['registerErrorPasswordSame'];
                        $errors .= "<br>";
                    }

                    if ($accountMail != $accountMail2) {
                        $errors .= $LNG['registerErrorMailSame'];
                        $errors .= "<br>";
                    }

                    if (!$this->accountUtil()->isNameValid($accountName)) {
                        $errors .= $LNG['registerErrorUsernameChar'];
                        $errors .= "<br>";
                    }

                    if ($ExistsUser != 0) {
                        $errors .= $LNG['registerErrorUsernameExist'];
                        $errors .= "<br>";
                    }

                    if (!empty($errors)) {
                        $this->message(new \Twig\Markup($errors, 'UTF-8'), '?page=creator&mode=account', 10, true);
                        throw new \App\Game\Core\EndOfRequest();
                    }

                    $Language = array_key_exists($Language, $LNG->getAllowedLangs(false)) ? $Language : $config->lang;
                    $id = $this->accountUtil()->createAccount(
                        $accountName,
                        $this->accountUtil()->cryptPassword($accountPass),
                        $accountMail,
                        $Language,
                        $authlevel,
                    );

                    $this->message(sprintf($LNG['new_account_success'], $id), '?page=creator&mode=account', 5, true);
                    throw new \App\Game\Core\EndOfRequest();
                }

                $AUTH = [];
                $AUTH[AUTH_USR] = $LNG['user_level_' . AUTH_USR];

                if ($USER['authlevel'] >= AUTH_OPS) {
                    $AUTH[AUTH_OPS] = $LNG['user_level_' . AUTH_OPS];
                }

                if ($USER['authlevel'] >= AUTH_MOD) {
                    $AUTH[AUTH_MOD] = $LNG['user_level_' . AUTH_MOD];
                }

                if ($USER['authlevel'] >= AUTH_ADM) {
                    $AUTH[AUTH_ADM] = $LNG['user_level_' . AUTH_ADM];
                }

                $this->assign([
                    'admin_auth'          => $USER['authlevel'],
                    'accountID'           => $LNG['accountID'],
                    'new_add_account'     => $LNG['new_add_account'],
                    'new_creator_refresh' => $LNG['new_creator_refresh'],
                    'new_creator_go_back' => $LNG['new_creator_go_back'],
                    'universe'            => $LNG['mu_universe'],
                    'user_reg'            => $LNG['user_reg'],
                    'pass_reg'            => $LNG['pass_reg'],
                    'pass2_reg'           => $LNG['pass2_reg'],
                    'email_reg'           => $LNG['email_reg'],
                    'email2_reg'          => $LNG['email2_reg'],
                    'new_coord'           => $LNG['new_coord'],
                    'new_range'           => $LNG['new_range'],
                    'lang_reg'            => $LNG['lang_reg'],
                    'new_title_acc'       => $LNG['new_title_acc'],
                    'Selector'            => ['auth' => $AUTH, 'lang' => $LNG->getAllowedLangs(false)],
                ]);
                return $this->render('admin/CreatePageAccount.html.twig');
            case 'user':
                $LNG->includeData(['PUBLIC']);
                if ($this->state->requestPost) {
                    $UserName = $this->http()->_GP('name', '', UTF8_SUPPORT);
                    $UserAuth = $this->http()->_GP('authlevel', 0);
                    $Galaxy = $this->http()->_GP('galaxy', 0);
                    $System = $this->http()->_GP('system', 0);
                    $Planet = $this->http()->_GP('planet', 0);
                    $Language = $this->http()->_GP('lang', '');
                    $accountID = $this->http()->_GP('accountID', 0);

                    $db = $this->db;
                    $sql = "SELECT COUNT(*) as count FROM %%USERS%% WHERE universe = :universe AND username = :username;";
                    $ExistsUser = $db->selectSingle($sql, [':universe' => $this->universe()->getEmulated(), ':username' => $UserName], 'count');

                    $sql = "SELECT COUNT(*) as count FROM %%USERS%% u INNER JOIN %%ACCOUNTS_TO_USERS%% atu on atu.user_id = u.id WHERE u.universe = :universe AND atu.account_id = :accountId;";
                    $AccountHasUser = $db->selectSingle($sql, [':universe' => $this->universe()->getEmulated(), ':accountId' => $accountID], 'count');

                    $errors = "";

                    $config = $this->configs->get($this->universe()->getEmulated());

                    if (empty($accountID)) {
                        $errors .= $LNG['empty_account_field'];
                        $errors .= "<br>";
                    }

                    if (empty($UserName)) {
                        $errors .= $LNG['registerErrorUsernameEmpty'];
                        $errors .= "<br>";
                    }

                    if (!$this->playerUtil()->isNameValid($UserName)) {
                        $errors .= $LNG['registerErrorUsernameChar'];
                        $errors .= "<br>";
                    }

                    if ($ExistsUser != 0) {
                        $errors .= $LNG['registerErrorUsernameExist'];
                        $errors .= "<br>";
                    }

                    if ($AccountHasUser != 0) {
                        $errors .= $LNG['account_already_has_user'];
                        $errors .= "<br>";
                    }

                    if (!$this->playerUtil()->isPositionFree($this->universe()->getEmulated(), $Galaxy, $System, $Planet)) {
                        $errors .= $LNG['po_complete_all'];
                        $errors .= "<br>";
                    }

                    if ($Galaxy > $config->max_galaxy || $System > $config->max_system || $Planet > $config->max_planets) {
                        $errors .= $LNG['po_complete_all2'];
                        $errors .= "<br>";
                    }

                    if (!empty($errors)) {
                        $this->message(new \Twig\Markup($errors, 'UTF-8'), '?page=creator&mode=user', 10, true);
                        throw new \App\Game\Core\EndOfRequest();
                    }

                    $Language = array_key_exists($Language, $LNG->getAllowedLangs(false)) ? $Language : $config->lang;

                    $player = $this->playerUtil()->createPlayer(
                        $accountID,
                        $this->universe()->getEmulated(),
                        $UserName,
                        $Language,
                        $Galaxy,
                        $System,
                        $Planet,
                        $LNG['fcm_planet'],
                        $UserAuth,
                    );

                    $this->message(sprintf($LNG['new_user_success'], $player[0], $player[1]), '?page=creator&mode=user', 5, true);
                    throw new \App\Game\Core\EndOfRequest();
                }

                $AUTH = [];
                $AUTH[AUTH_USR] = $LNG['user_level_' . AUTH_USR];

                if ($USER['authlevel'] >= AUTH_OPS) {
                    $AUTH[AUTH_OPS] = $LNG['user_level_' . AUTH_OPS];
                }

                if ($USER['authlevel'] >= AUTH_MOD) {
                    $AUTH[AUTH_MOD] = $LNG['user_level_' . AUTH_MOD];
                }

                if ($USER['authlevel'] >= AUTH_ADM) {
                    $AUTH[AUTH_ADM] = $LNG['user_level_' . AUTH_ADM];
                }

                $this->assign([
                    'admin_auth'          => $USER['authlevel'],
                    'accountID'           => $LNG['accountID'],
                    'new_add_user'        => $LNG['new_add_user'],
                    'new_creator_refresh' => $LNG['new_creator_refresh'],
                    'new_creator_go_back' => $LNG['new_creator_go_back'],
                    'universe'            => $LNG['mu_universe'],
                    'user_reg'            => $LNG['user_reg'],
                    'new_coord'           => $LNG['new_coord'],
                    'new_range'           => $LNG['new_range'],
                    'lang_reg'            => $LNG['lang_reg'],
                    'new_title'           => $LNG['new_title'],
                    'Selector'            => ['auth' => $AUTH, 'lang' => $LNG->getAllowedLangs(false)],
                ]);
                return $this->render('admin/CreatePageUser.html.twig');
            case 'moon':
                if ($this->state->requestPost) {
                    $db = $this->db;

                    $PlanetID = $this->http()->_GP('add_moon', 0);
                    $MoonName = $this->http()->_GP('name', '', UTF8_SUPPORT);
                    $Diameter = $this->http()->_GP('diameter', 0);

                    $MoonPlanet = $db->selectSingle(
                        "SELECT temp_max, temp_min, id_luna, galaxy, `system`, planet, planet_type, destruyed, id_owner FROM %%PLANETS%% WHERE id = :planetId AND universe = :universe AND planet_type = 1 AND destruyed = 0;",
                        [
                            ':planetId' => $PlanetID,
                            ':universe' => $this->universe()->getEmulated(),
                        ],
                    );

                    if (!isset($MoonPlanet)) {
                        $this->message($LNG['mo_planet_doesnt_exist'], '?page=creator&mode=moon', 3, true);
                        throw new \App\Game\Core\EndOfRequest();
                    }

                    $moonId = $this->playerUtil()->createMoon(
                        $this->universe()->getEmulated(),
                        $MoonPlanet['galaxy'],
                        $MoonPlanet['system'],
                        $MoonPlanet['planet'],
                        $MoonPlanet['id_owner'],
                        20,
                        $MoonName,
                        (($this->state->requestPost['diameter_check'] ?? '') === 'on' ? null : $Diameter),
                    );

                    if ($moonId !== false) {
                        $this->message($LNG['mo_moon_added'], '?page=creator&mode=moon', 3, true);
                    } else {
                        $this->message($LNG['mo_moon_unavaible'], '?page=creator&mode=moon', 3, true);
                    }
                    throw new \App\Game\Core\EndOfRequest();
                }

                $this->assign([
                    'admin_auth'          => $USER['authlevel'],
                    'universum'           => $LNG['mu_universe'],
                    'po_add_moon'         => $LNG['po_add_moon'],
                    'input_id_planet'     => $LNG['input_id_planet'],
                    'mo_moon_name'        => $LNG['mo_moon_name'],
                    'mo_diameter'         => $LNG['mo_diameter'],
                    'mo_temperature'      => $LNG['mo_temperature'],
                    'mo_fields_avaibles'  => $LNG['mo_fields_avaibles'],
                    'button_add'          => $LNG['button_add'],
                    'new_creator_refresh' => $LNG['new_creator_refresh'],
                    'mo_moon'             => $LNG['fcm_moon'],
                    'new_creator_go_back' => $LNG['new_creator_go_back'],
                ]);

                return $this->render('admin/CreatePageMoon.html.twig');
            case 'planet':
                if ($this->state->requestPost) {
                    $db = $this->db;

                    $id = $this->http()->_GP('id', 0);
                    $Galaxy = $this->http()->_GP('galaxy', 0);
                    $System = $this->http()->_GP('system', 0);
                    $Planet = $this->http()->_GP('planet', 0);
                    $name = $this->http()->_GP('name', '', UTF8_SUPPORT);
                    $field_max = $this->http()->_GP('field_max', 0);

                    $config = $this->configs->get($this->universe()->getEmulated());

                    if ($Galaxy > $config->max_galaxy || $System > $config->max_system || $Planet > $config->max_planets) {
                        $this->message($LNG['po_complete_all2'], '?page=creator&mode=planet', 3, true);
                        throw new \App\Game\Core\EndOfRequest();
                    }


                    $ISUser = $db->selectSingle(
                        "SELECT id, authlevel FROM %%USERS%% WHERE id = :id AND universe = :universe;",
                        [
                            ':id'       => $id,
                            ':universe' => $this->universe()->getEmulated(),
                        ],
                    );
                    if (!$this->playerUtil()->checkPosition($this->universe()->getEmulated(), $Galaxy, $System, $Planet) || !isset($ISUser)) {
                        $this->message($LNG['po_complete_all'], '?page=creator&mode=planet', 3, true);
                        throw new \App\Game\Core\EndOfRequest();
                    }

                    $planetId = $this->playerUtil()->createPlanet(
                        $Galaxy,
                        $System,
                        $Planet,
                        $this->universe()->getEmulated(),
                        $id,
                        null,
                        false,
                        $ISUser['authlevel'],
                    );

                    // optional field limit and name (the former string building produced "SET , name" without a field limit)
                    $set = [];
                    $params = [':planetId' => $planetId];
                    if ($field_max > 0) {
                        $set[] = 'field_max = :fieldMax';
                        $params[':fieldMax'] = (int) $field_max;
                    }
                    if (!empty($name)) {
                        $set[] = 'name = :name';
                        $params[':name'] = $name;
                    }
                    if ($set !== []) {
                        $db->update('UPDATE %%PLANETS%% SET ' . implode(', ', $set) . ' WHERE id = :planetId;', $params);
                    }

                    $this->message($LNG['po_complete_succes'], '?page=creator&mode=planet', 3, true);
                    throw new \App\Game\Core\EndOfRequest();
                }

                $this->assign([
                    'admin_auth'          => $USER['authlevel'],
                    'po_add_planet'       => $LNG['po_add_planet'],
                    'po_galaxy'           => $LNG['po_galaxy'],
                    'po_system'           => $LNG['po_system'],
                    'po_planet'           => $LNG['po_planet'],
                    'input_id_user'       => $LNG['input_id_user'],
                    'new_creator_coor'    => $LNG['new_creator_coor'],
                    'po_name_planet'      => $LNG['po_name_planet'],
                    'po_fields_max'       => $LNG['po_fields_max'],
                    'button_add'          => $LNG['button_add'],
                    'po_colony'           => $LNG['fcp_colony'],
                    'new_creator_refresh' => $LNG['new_creator_refresh'],
                    'new_creator_go_back' => $LNG['new_creator_go_back'],
                ]);

                return $this->render('admin/CreatePagePlanet.html.twig');
            case 'testData':
                if ($this->state->requestPost) {
                    $this->createTestUser();
                    $this->message($LNG['test_data_success'], '?page=creator', 3, true);
                    throw new \App\Game\Core\EndOfRequest();
                }
                $this->assign([
                    'gameStages'          => [
                        1 => $LNG['test_data_stage'][0],
                        2 => $LNG['test_data_stage'][1],
                        3 => $LNG['test_data_stage'][2],
                        4 => $LNG['test_data_stage'][3],
                    ],
                ]);

                return $this->render('admin/CreatePageTestUser.html.twig');
            default:

                return $this->render('admin/CreatePage.html.twig');
        }
    }

    private function createTestUser(): void
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;

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

    private function setTestTechData($userId, $stageData): void
    {
        $resource = & $this->state->resource;

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

    private function setTestPlanetData($planetId, $stageData): void
    {
        $resource = & $this->state->resource;

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

    private function setTestMoonData($moonId, $stageData): void
    {
        $resource = & $this->state->resource;

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
