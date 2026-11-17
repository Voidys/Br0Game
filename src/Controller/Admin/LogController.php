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

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\StoredData;

/**
 * Admin action log: overview, list per log type (planets, players, settings, presents, alliances) and the detail view.
 */
class LogController extends AbstractAdminController
{
    /**
     * The log categories. Log modes: 1 player changes (target = player id), 2 planet changes (target = planet id), 3 settings
     * pages (target 0 server, 1 universe, 2 stat, 5 imprint), 4 presents, 5 transport missions, 6 alliance changes (target = alliance id).
     */
    #[GameRoute('admin', 'admin/logs', 'log')]
    public function show(): Response
    {
        return $this->render('admin/LogOverview.html.twig');
    }

    /**
     * Flattens one stored admin-log change set (nested arrays of old/new values) into display rows: nested keys that
     * are table names start a table section, scalar values become rows via addToLogArray(); the universe key is skipped.
     *
     * @param array<int, array<string, mixed>> $LogArray rows collected so far (appended in place)
     */
    private function loopLogArray(&$LogArray, $Wrapper, $dbTableNames, $conf_after, $array)
    {
        foreach ($array as $key => $val) {
            if ($key != 'universe') {
                if (is_array($val)) {
                    if (array_key_exists($key, $dbTableNames)) {
                        $LogArray[] = [
                            'Table' => $key,
                            'key'   => $key,
                        ];
                    }
                    $this->loopLogArray($LogArray, $Wrapper, $dbTableNames, $conf_after, $val);
                } else {
                    $this->addToLogArray($LogArray, $Wrapper, $conf_after, $key, $val);
                }
            }
        }
    }

    /**
     * Formats one old/new value pair of a log entry for display: picks a readable label from the language keys and prints booleans,
     * timestamps and numbers accordingly.
     */
    private function addToLogArray(&$LogArray, $Wrapper, $conf_after, $key, $val)
    {
        $LNG = $this->state->LNG;

        if (isset($LNG['tech'][$key])) {
            $Element = $LNG['tech'][$key];
        } elseif (isset($LNG['se_' . $key])) {
            $Element = $LNG['se_' . $key];
        } elseif (isset($LNG[$key])) {
            $Element = $LNG[$key];
        } elseif (isset($Wrapper[$key])) {
            $Element = $Wrapper[$key];
        } else {
            $Element = $key;
        }

        if ($Element == 'urlaubs_until') {
            $oldVaue = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $val);
        } elseif (is_numeric($val)) {
            $oldVaue = $this->viewHelpers()->prettyNumber($val);
        } else {
            $oldVaue = $val;
        }

        if (!isset($conf_after[$key])) {
            $newVaue = $LNG['adm_log_not_found'];
        } elseif ($Element == 'urlaubs_until') {
            $newVaue = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $conf_after[$key]);
        } elseif (is_numeric($conf_after[$key])) {
            $newVaue = $this->viewHelpers()->prettyNumber($conf_after[$key]);
        } else {
            $newVaue = $conf_after[$key];
        }

        $LogArray[] = [
            'Element' => $Element,
            'old'     => $oldVaue,
            'new'     => $newVaue,
        ];
    }

    /**
     * One log entry (request: id): who changed what, with the old and new values side by side.
     */
    #[GameRoute('admin', 'admin/logs/{id}', 'log', 'detail', requirements: ['id' => '\d+'])]
    public function detail(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $logid = $this->input()->int('id');
        $sql = "SELECT l.*, u_a.`username` as admin_username FROM %%LOG%% AS l
        LEFT JOIN %%USERS%% AS u_a ON u_a.`id` = l.`admin`
        WHERE l.`id` = :logid;";
        $result = $this->db->selectSingle($sql, [
            ':logid' => $logid,
        ]);

        if (empty($result)) {
            return $this->messageResponse($LNG['log_no_data']);
        }
        $data = StoredData::tryDecode($result['data']);
        if (!is_array($data)) {
            $data = [];
        }

        [$conf_before, $conf_after] = $this->splitLogData($data);

        $Wrapper = $this->logFieldLabels();

        $LogArray = [];

        $dbTableNames = [];
        require_once 'src/Game/Support/tables.php';
        $this->loopLogArray($LogArray, $Wrapper, $dbTableNames, $conf_after, $conf_before);

        $this->collectNestedLogRows($LogArray, $Wrapper, $conf_before, $conf_after);

        return $this->render('admin/LogDetail.html.twig', [
            'LogArray'     => $LogArray,
            'admin'        => $result['admin_username'],
            'target'       => $result['universe'],
            'id'           => $result['id'],
            'time'         => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $result['time'], $USER['timezone']),
            'log_info'     => $LNG['log_info'],
            'log_admin'    => $LNG['log_admin'],
            'log_time'     => $LNG['log_time'],
            'log_target'   => $LNG['log_universe'],
            'log_id'       => $LNG['log_id'],
            'log_element'  => $LNG['log_element'],
            'log_old'      => $LNG['log_old'],
            'log_new'      => $LNG['log_new'],
        ]);
    }

    /**
     * Splits the stored log data into the "before" and "after" values: entries with two arrays are before/after pairs,
     * everything else (e.g. gifts) is a flat "before" list.
     *
     * @param array<string, mixed> $data
     * @return array{0: array, 1: array} before and after values
     */
    private function splitLogData(array $data): array
    {
        $conf_before = [];
        $conf_after = [];
        // before/after pairs; other entries (e.g. gifts) only hold a flat list of values
        if (isset($data[0], $data[1]) && is_array($data[0]) && is_array($data[1])) {
            $before = $data[0];
            $after = $data[1];
        } else {
            $before = $data;
            $after = [];
        }
        foreach ($before as $key => $i) {
            $conf_before[$key] = $i;
        }
        foreach ($after as $key => $i) {
            $conf_after[$key] = $i;
        }

        return [$conf_before, $conf_after];
    }

    /**
     * Display labels of the config and editor keys that appear in log entries.
     *
     * @return array<string, string>
     */
    private function logFieldLabels(): array
    {
        $LNG = $this->state->LNG;

        return [
            'resource_multiplier' => $LNG['se_resources_producion_speed'],
            'forum_url'           => $LNG['se_forum_link'],

            'stat_settings'    => $LNG['cs_point_per_resources_used'],
            'stat'             => $LNG['cs_points_to_zero'],
            'stat_update_time' => $LNG['cs_time_between_updates'],
            'stat_level'       => $LNG['cs_access_lvl'],


            'metal'     => $LNG['tech'][901],
            'crystal'   => $LNG['tech'][902],
            'deuterium' => $LNG['tech'][903],

            'authattack' => $LNG['qe_authattack'],
            'username'   => $LNG['adm_username'],
            'field_max'  => $LNG['qe_fields'],
        ];
    }

    /**
     * Adds the rows for the nested before-values (tables with keys, up to three levels) next to their "after" values.
     *
     * @param array<mixed> $LogArray result rows (appended to)
     * @param array<mixed> $Wrapper
     * @param array<mixed> $conf_after
     * @param array<mixed> $conf_before
     */
    private function collectNestedLogRows(array &$LogArray, array $Wrapper, array $conf_before, array $conf_after): void
    {
        foreach ($conf_before as $key => $val) {
            if ($key != 'universe') {
                if (is_array($val)) {
                    foreach ($val as $key2 => $val2) {
                        $LogArray[] = [
                            'Table' => $key,
                            'key'   => $key2,
                        ];
                        if (is_array($val2)) {
                            foreach ($val2 as $key3 => $val3) {
                                if (is_array($val3)) {
                                    foreach ($val3 as $key4 => $val4) {
                                        $this->addToLogArray($LogArray, $Wrapper, $conf_after, $key4, $val4);
                                    }
                                } else {
                                    $this->addToLogArray($LogArray, $Wrapper, $conf_after, $key3, $val3);
                                }
                            }
                        } else {
                            $this->addToLogArray($LogArray, $Wrapper, $conf_after, $key2, $val2);
                        }
                    }
                } else {
                    $this->addToLogArray($LogArray, $Wrapper, $conf_after, $key, $val);
                }
            }
        }
    }

    /**
     * Log entries of universe settings changes with the acting admin; a message when there are none.
     */
    #[GameRoute('admin', 'admin/logs/settings', 'log', 'settings')]
    public function settings(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $result = $db->select("SELECT l.id, l.admin, l.time, l.universe, l.target,u_a.username as"
            . " admin_username FROM %%LOG%% as l LEFT JOIN %%USERS%% as u_a ON  u_a.id = l.admin WHERE mode = 3"
            . " ORDER BY id DESC");

        if (empty($result)) {
            return $this->messageResponse($LNG['log_no_data'], $this->router->generate('admin_log_show'));
        }

        $targetkey = [
            0 => $LNG['log_ssettings'],
            1 => $LNG['log_usettings'],
            2 => $LNG['log_statsettings'],
            3 => '<removed>', // previously used for the chat
            4 => $LNG['log_tssettings'],
            5 => $LNG['log_disclamersettings'],
        ];

        foreach ($result as $LogRow) {
            $LogArray[] = [
                'id'         => $LogRow['id'],
                'admin'      => $LogRow['admin_username'],
                'target_uni' => ($LogRow['target'] == 0 ? '' : $LogRow['universe']),
                'target'     => $targetkey[$LogRow['target']],
                'time'       => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $LogRow['time'], $USER['timezone']),
            ];
        }

        if (!isset($LogArray)) {
            $LogArray = [];
        }
        return $this->render('admin/LogList.html.twig', [
            'LogArray'   => $LogArray,
            'log_log'    => $LNG['log_log'],
            'log_admin'  => $LNG['log_admin'],
            'log_time'   => $LNG['log_time'],
            'log_uni'    => $LNG['log_uni_short'],
            'log_target' => $LNG['log_target_universe'],
            'log_id'     => $LNG['log_id'],
            'log_view'   => $LNG['log_view'],
        ]);
    }

    /**
     * Log entries of planet edits (quick editor): planet, owner and acting admin.
     */
    #[GameRoute('admin', 'admin/logs/planet', 'log', 'planet')]
    public function planets(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $result = $db->select("SELECT DISTINCT l.id, l.admin, l.target, l.time, l.universe, u_t.username
             as target_username, p.galaxy as target_galaxy, p.system as target_system, p.planet as target_planet,
             u_a.username as admin_username FROM %%LOG%% as l LEFT JOIN %%USERS%% as u_a ON  u_a.id = l.admin
             LEFT JOIN %%PLANETS%% as p ON p.id = l.target LEFT JOIN %%USERS%% as u_t ON u_t.id = p.id_owner
             WHERE mode = 2 ORDER BY id DESC");
        if (empty($result)) {
            return $this->messageResponse($LNG['log_no_data'], $this->router->generate('admin_log_show'));
        }

        foreach ($result as $LogRow) {
            $LogArray[] = [
                'id'         => $LogRow['id'],
                'admin'      => $LogRow['admin_username'],
                'target_uni' => $LogRow['universe'],
                'target'     => '[' . $LogRow['target_galaxy'] . ':' . $LogRow['target_system'] . ':'
                    . $LogRow['target_planet'] . '] -> ' . $LogRow['target_username'],
                'time'       => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $LogRow['time'], $USER['timezone']),
            ];
        }

        if (!isset($LogArray)) {
            $LogArray = [];
        }
        return $this->render('admin/LogList.html.twig', [
            'LogArray'     => $LogArray,
            'log_log'      => $LNG['log_log'],
            'log_admin'    => $LNG['log_admin'],
            'log_time'     => $LNG['log_time'],
            'log_uni'      => $LNG['log_uni_short'],
            'log_target'   => $LNG['log_target_planet'],
            'log_id'       => $LNG['log_id'],
            'log_view'     => $LNG['log_view'],
        ]);
    }

    /**
     * Log entries of player edits (quick editor, account editor).
     */
    #[GameRoute('admin', 'admin/logs/player', 'log', 'player')]
    public function players(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $result = $db->select("SELECT DISTINCT l.id, l.admin, l.target, l.time, l.universe,u_t.username
             as target_username, u_a.username as admin_username FROM %%LOG%% as l 
             LEFT JOIN %%USERS%% as u_a ON  u_a.id = l.admin 
             LEFT JOIN %%USERS%% as u_t ON u_t.id = l.target 
             WHERE mode = 1 
             ORDER BY l.id DESC");

        if (empty($result)) {
            return $this->messageResponse($LNG['log_no_data'], $this->router->generate('admin_log_show'));
        }

        foreach ($result as $LogRow) {
            $LogArray[] = [
                'id'         => $LogRow['id'],
                'admin'      => $LogRow['admin_username'],
                'target_uni' => $LogRow['universe'],
                'target'     => $LogRow['target_username'],
                'time'       => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $LogRow['time'], $USER['timezone']),
            ];
        }

        if (!isset($LogArray)) {
            $LogArray = [];
        }
        return $this->render('admin/LogList.html.twig', [
            'LogArray'   => $LogArray,
            'log_log'    => $LNG['log_log'],
            'log_admin'  => $LNG['log_admin'],
            'log_time'   => $LNG['log_time'],
            'log_uni'    => $LNG['log_uni_short'],
            'log_target' => $LNG['log_target_user'],
            'log_id'     => $LNG['log_id'],
            'log_view'   => $LNG['log_view'],
        ]);
    }

    /**
     * Log entries of gifts (giveaway).
     */
    #[GameRoute('admin', 'admin/logs/present', 'log', 'present')]
    public function present(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $result = $db->select("SELECT DISTINCT l.id, l.admin, l.target, l.time, l.universe,u_a.username
             as admin_username FROM %%LOG%% as l LEFT JOIN %%USERS%% as u_a ON  u_a.id = l.admin WHERE mode = 4
            ORDER BY l.id DESC;");
        if (empty($result)) {
            return $this->messageResponse($LNG['log_no_data'], $this->router->generate('admin_log_show'));
        }

        foreach ($result as $LogRow) {
            $LogArray[] = [
                'id'         => $LogRow['id'],
                'admin'      => $LogRow['admin_username'],
                'target_uni' => $LogRow['universe'],
                'target'     => $LNG['fcm_universe'],
                'time'       => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $LogRow['time'], $USER['timezone']),
            ];
        }
        if (!isset($LogArray)) {
            $LogArray = [];
        }
        return $this->render('admin/LogList.html.twig', [
            'LogArray'   => $LogArray,
            'log_log'    => $LNG['log_log'],
            'log_admin'  => $LNG['log_admin'],
            'log_time'   => $LNG['log_time'],
            'log_uni'    => $LNG['log_uni_short'],
            'log_target' => $LNG['log_target_user'],
            'log_id'     => $LNG['log_id'],
            'log_view'   => $LNG['log_view'],
        ]);
    }

    /**
     * Log entries of alliance edits with the alliance names.
     */
    #[GameRoute('admin', 'admin/logs/alliance', 'log', 'alliance')]
    public function alliance(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $sql = "SELECT DISTINCT l.`id`, u_a.`username` AS admin_username, l.`universe`, l.`time`,
        CONCAT(a.`id`, ' - ', a.`ally_name`, ' [', a.`ally_tag`, ']') AS alliance
        FROM %%LOG%% AS l
        LEFT JOIN %%USERS%% AS u_a ON u_a.`id` = l.`admin`
        LEFT JOIN %%ALLIANCE%% AS a ON a.`id` = l.`target`
        WHERE mode = 6
        ORDER BY l.`id` DESC;";
        $result = $this->db->select($sql);

        if (empty($result)) {
            return $this->messageResponse($LNG['log_no_data'], $this->router->generate('admin_log_show'));
        }

        foreach ($result as $LogRow) {
            $LogArray[] = [
                'id'         => $LogRow['id'],
                'admin'      => $LogRow['admin_username'],
                'target_uni' => $LogRow['universe'],
                'target'     => $LogRow['alliance'],
                'time'       => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $LogRow['time'], $USER['timezone']),
            ];
        }
        if (!isset($LogArray)) {
            $LogArray = [];
        }
        return $this->render('admin/LogList.html.twig', [
            'LogArray'   => $LogArray,
            'log_log'    => $LNG['log_log'],
            'log_admin'  => $LNG['log_admin'],
            'log_time'   => $LNG['log_time'],
            'log_uni'    => $LNG['log_uni_short'],
            'log_target' => $LNG['log_target_alliance'],
            'log_id'     => $LNG['log_id'],
            'log_view'   => $LNG['log_view'],
        ]);
    }
}
