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

use DateTime;
use DirectoryIterator;
use Exception;
use App\Game\Cronjob\Cronjob;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use Symfony\Component\HttpFoundation\Response;

/** Manages the cronjob table: list, edit, lock, enable and run jobs. */
#[\App\Routing\GamePage('admin.php', 'cronjob')]
#[\App\Routing\GamePage('admin.php', 'cronjob', uniPrefix: true)]
class CronjobController extends AbstractAdminController
{
    /**
     * Dispatches on `action` (default overview): edit, delete, lock, unlock, detail, enable, run, overview. Request: id (cronjob id).
     * Mutating actions end with a redirect to admin?page=cronjob.
     */
    public function show(): Response
    {
        $cronId = $this->http()->_GP('id', 0);

        switch ($this->http()->_GP('action', 'overview')) {
            case 'edit':
                return $this->ShowCronjobEdit($cronId);
                // the following actions end the request with a redirect
            case 'delete':
                $this->ShowCronjobDelete($cronId);
                // no break
            case 'lock':
                $this->ShowCronjobLock($cronId);
                // no break
            case 'unlock':
                $this->ShowCronjobUnlock($cronId);
                // no break
            case 'detail':
                return $this->ShowCronjobDetail($cronId);
            case 'enable':
                $this->ShowCronjobEnable($cronId);
                // no break
            case 'run':
                $this->ShowCronjobRun($cronId);
                // no break
            case 'overview':
            default:
                return $this->ShowCronjobOverview();
        }
    }

    /**
     * Edit/create form handler. With POST (name, class and the schedule fields min, hours, dom, month, dow) validates and updates
     * the job `$post_id`, or inserts a new one when it is 0; then redirects to the list. Without POST it renders the form.
     */
    public function ShowCronjobEdit($post_id): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $post_name = $this->http()->_GP('name', '');
        $post_min = $this->checkPostData('min', 59);
        $post_hours = $this->checkPostData('hours', 23);
        $post_month = $this->checkPostData('month', 12);
        $post_dow = $this->checkPostData('dow', 6);
        $post_dom = $this->checkPostData('dom', 31);
        $post_class = $this->http()->_GP('class', '');
        $error_msg = [];

        if ($post_name == '') {
            $error_msg[] = $LNG['cronjob_error_name'];
        }
        if ($post_min === false) {
            $error_msg[] = $LNG['cronjob_error_min'];
        }
        if ($post_hours === false) {
            $error_msg[] = $LNG['cronjob_error_hours'];
        }
        if ($post_month === false) {
            $error_msg[] = $LNG['cronjob_error_month'];
        }
        if ($post_dow === false) {
            $error_msg[] = $LNG['cronjob_error_dow'];
        }
        if ($post_dom === false) {
            $error_msg[] = $LNG['cronjob_error_dom'];
        }
        if ($post_class == '') {
            $error_msg[] = $LNG['cronjob_error_class'];
        } elseif (!preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $post_class) || !file_exists('src/Game/Cronjob/' . ucfirst($post_class) . '.php')) {
            $error_msg[] = $LNG['cronjob_error_filenotfound'] . 'src/Game/Cronjob/' . ucfirst($post_class) . '.php';
        }

        if (count($error_msg) == 0) {
            if ($post_id != 0) {
                $db->update(
                    "UPDATE %%CRONJOBS%% SET name = :name, min = :min, hours = :hours, month = :month, dow = :dow, dom = :dom, class = :class WHERE cronjobID = :cronjobID;",
                    [
                        ':name' => $post_name,
                        ':min' => $post_min,
                        ':hours' => $post_hours,
                        ':month' => $post_month,
                        ':dow' => $post_dow,
                        ':dom' => $post_dom,
                        ':class' => $post_class,
                        ':cronjobID' => $post_id,
                    ],
                );
            } else {
                $db->insert(
                    "INSERT INTO %%CRONJOBS%% SET name = :name, min = :min, hours = :hours, month = :month, dow = :dow, dom = :dom, class = :class;",
                    [
                        ':name' => $post_name,
                        ':min' => $post_min,
                        ':hours' => $post_hours,
                        ':month' => $post_month,
                        ':dow' => $post_dow,
                        ':dom' => $post_dom,
                        ':class' => $post_class,
                    ],
                );
            }

            $this->http()->redirectTo('admin?page=cronjob');
        } else {
            return $this->ShowCronjobDetail($post_id, $error_msg);
        }
    }

    /**
     * Deletes the cronjob and its log rows, then redirects to the list.
     */
    public function ShowCronjobDelete($cronjobId): never
    {
        $db = $this->db;
        $db->delete(
            "DELETE FROM %%CRONJOBS%% WHERE cronjobID = :cronjobID;",
            [
                ':cronjobID' => $cronjobId,
            ],
        );
        $db->delete(
            "DELETE FROM %%CRONJOBS_LOG%% WHERE cronjobID = :cronjobID;",
            [
                ':cronjobID' => $cronjobId,
            ],
        );
        $this->http()->redirectTo('admin?page=cronjob');
    }

    /**
     * Locks the job (`lock` = hash of the current time) so the cron runner skips it, then redirects.
     */
    public function ShowCronjobLock($cronjobId): never
    {
        $db = $this->db;
        $db->update(
            "UPDATE %%CRONJOBS%% SET `lock` = MD5(UNIX_TIMESTAMP()) WHERE cronjobID = :cronjobID;",
            [
                ':cronjobID' => $cronjobId,
            ],
        );
        $this->http()->redirectTo('admin?page=cronjob');
    }

    /**
     * Removes the lock of the job (e.g. after a crashed run), then redirects.
     */
    public function ShowCronjobUnlock($cronjobId): never
    {
        $db = $this->db;
        $db->update(
            "UPDATE %%CRONJOBS%% SET `lock` = NULL WHERE cronjobID = :cronjobID;",
            [
                ':cronjobID' => $cronjobId,
            ],
        );
        $this->http()->redirectTo('admin?page=cronjob');
    }

    /**
     * Runs the job once now (through Cronjob::execute), removes its lock, shows a message and redirects.
     */
    public function ShowCronjobRun($cronjobId): never
    {
        $db = $this->db;

        // Clear any stale lock first so a manual run is never blocked by a previous crashed/interrupted execution.
        $db->update(
            "UPDATE %%CRONJOBS%% SET `lock` = NULL WHERE cronjobID = :cronjobID;",
            [
                ':cronjobID' => $cronjobId,
            ],
        );

        try {
            $this->cronjobs()->execute((int) $cronjobId, true);
        } catch (Exception $e) {
            // Cronjob::execute() handles failures of the job's own run() internally
            // But here we are catching inactive/unknown jobs.
            $this->message($e->getMessage(), 'admin?page=cronjob');
        }

        $this->http()->redirectTo('admin?page=cronjob');
    }

    /**
     * Switches the job on or off (request: enable = 1/0 -> `isActive`), then redirects.
     */
    public function ShowCronjobEnable($cronjobId): never
    {
        $db = $this->db;
        $db->update(
            "UPDATE %%CRONJOBS%% SET `isActive` = :isActive WHERE cronjobID = :cronjobID;",
            [
                ':isActive' => $this->http()->_GP('enable', 0),
                ':cronjobID' => $cronjobId,
            ],
        );
        $this->http()->redirectTo('admin?page=cronjob');
    }

    /**
     * List of all cronjobs with their schedule, state and the time of the last run (from CRONJOBS_LOG).
     */
    public function ShowCronjobOverview(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $data = $db->select("SELECT * FROM %%CRONJOBS%%;");
        if (!$data) {
            $this->message($LNG['cronjob_no_data']);
        }
        $CronjobArray = [];
        foreach ($data as $CronjobRow) {
            $cronjobLogLastExecSql = "SELECT MAX(`executionTime`) AS lastExecution FROM %%CRONJOBS_LOG%%
                 WHERE cronjobID = :cronjobID;";
            $dataCronjobLog = $this->db->selectSingle($cronjobLogLastExecSql, [':cronjobID' => $CronjobRow['cronjobID']]);
            $lastExecutionTimestamp = 0;
            if (!is_null($dataCronjobLog['lastExecution'])) {
                $lastExecutionTimestamp = (new DateTime($dataCronjobLog['lastExecution']))->getTimestamp();
            }
            $lastExecutionTimestamp = isset($lastExecutionTimestamp) ? $lastExecutionTimestamp : 0;
            $CronjobArray[] = [
                'id'            => $CronjobRow['cronjobID'],
                'isActive'      => $CronjobRow['isActive'],
                'name'          => $CronjobRow['name'],
                'min'           => $CronjobRow['min'],
                'hours'         => $CronjobRow['hours'],
                'dom'           => $CronjobRow['dom'],
                'month'         => $this->getCronjobTimes($CronjobRow['month'], 12),
                'dow'           => $this->getCronjobTimes($CronjobRow['dow'], 6),
                'class'         => $CronjobRow['class'],
                'lastExecution' => $lastExecutionTimestamp,
                'nextTime'      => $CronjobRow['nextTime'],
                'lock'          => !empty($CronjobRow['lock']),
            ];
        }
        $this->assign([
            'CronjobArray' => $CronjobArray,
        ]);
        return $this->render('admin/CronjobOverview.html.twig');
    }

    /**
     * Edit form of one job (or an empty form): lists the job classes found in src/Game/Cronjob/ and fills the schedule fields. The
     * optional $error_msg is shown above the form after a failed save.
     */
    public function ShowCronjobDetail($detail, $error_msg = null): Response
    {
        $USER = $this->state->USER;
        $db = $this->db;

        $avalibleCrons = [];

        $dir = new DirectoryIterator('src/Game/Cronjob/');
        foreach ($dir as $fileinfo) {
            if ($fileinfo->isFile() && $fileinfo->getBasename('.php') != $fileinfo->getFilename()) {
                $avalibleCrons[] = $fileinfo->getBasename('.php');
            }
        }

        $this->assign(['avalibleCrons' => $avalibleCrons]);

        if ($detail != 0) {
            $CronjobRow = $db->selectSingle(
                "SELECT * FROM %%CRONJOBS%% WHERE cronjobID = :cronjobID;",
                [
                    ':cronjobID' => $detail,
                ],
            );
            $this->assign([
                'id'           => $CronjobRow['cronjobID'],
                'name'         => isset($this->state->requestPost['name']) ? $this->http()->_GP('name', '') : $CronjobRow['name'],
                'min'          => isset($this->state->requestPost['min_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['min']) ? $this->http()->_GP('min', []) : $this->getCronjobTimes($CronjobRow['min'], 59)
                ),
                'hours'        => isset($this->state->requestPost['hours_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['hours']) ? $this->http()->_GP('hours', []) : $this->getCronjobTimes($CronjobRow['hours'], 23)
                ),
                'dom'          => isset($this->state->requestPost['dom_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['dom']) ? $this->http()->_GP('dom', []) : $this->getCronjobTimes($CronjobRow['dom'], 31)
                ),
                'month'        => isset($this->state->requestPost['month_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['month']) ? $this->http()->_GP('month', []) : $this->getCronjobTimes($CronjobRow['month'], 12)
                ),
                'dow'          => isset($this->state->requestPost['dow_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['dow']) ? $this->http()->_GP('dow', []) : $this->getCronjobTimes($CronjobRow['dow'], 6)
                ),
                'class'        => isset($this->state->requestPost['class']) ? $this->http()->_GP('class', '') : $CronjobRow['class'],
                'error_msg'    => $error_msg,
            ]);
        } else {
            $this->assign([
                'id'           => 'add',
                'name'         => $this->http()->_GP('name', ''),
                'min'          => isset($this->state->requestPost['min_all']) ? [0 => '*'] : $this->http()->_GP('min', []),
                'hours'        => isset($this->state->requestPost['hours_all']) ? [0 => '*'] : $this->http()->_GP('hours', []),
                'dom'          => isset($this->state->requestPost['dom_all']) ? [0 => '*'] : $this->http()->_GP('dom', []),
                'month'        => isset($this->state->requestPost['month_all']) ? [0 => '*'] : $this->http()->_GP('month', []),
                'dow'          => isset($this->state->requestPost['dow_all']) ? [0 => '*'] : $this->http()->_GP('dow', []),
                'class'        => $this->http()->_GP('class', ''),
                'error_msg'    => $error_msg,
            ]);
        }
        return $this->render('admin/CronjobDetail.html.twig');
    }

    private function getCronjobTimes($row, $max): array|string
    {
        $arr = explode(',', $row);
        if (count($arr) > 1) {
            return $arr;
        }

        if (substr($arr[0], 0, (2 - strlen($arr[0]))) == '*/') {
            return range(0, $max, (int) substr($arr[0], (2 - strlen($arr[0]))));
        } else {
            return $arr[0];
        }
    }

    /**
     * Converts the checkbox list of one schedule column into cron syntax: `*` when "all" is ticked, the every-n form (`*` + `/n`) when the selected values
     * are exactly every n-th value of 0..$max (n among 2,3,5,6,7,10,14,15,20,30; the largest fitting n wins), otherwise the comma list.
     * False when nothing is selected.
     */
    private function checkPostData($column, $max): false|string
    {
        $all = $this->http()->_GP($column . '_all', 0);
        if ($all) {
            return '*';
        }

        $post = $this->http()->_GP($column, []);
        $post = array_filter($post, 'is_numeric');
        if (empty($post)) {
            return false;
        }

        $check = [2, 3, 5, 6, 7, 10, 14, 15, 20, 30];
        $result = [];
        foreach ($check as $i) {
            if ($i <= $max && range(0, $max, $i) == $post) {
                $result[] = $i;
            }
        }
        if (!empty($result)) {
            return '*/' . max($result);
        } else {
            return implode(',', $post);
        }
    }

}
