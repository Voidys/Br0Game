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
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Manages the cronjob table: list, edit, lock, enable and run jobs. */
class CronjobController extends AbstractAdminController
{
    /**
     * Edit/create form handler. POST (name, class and the schedule fields min, hours, dom, month, dow) validates and updates
     * the job `id`, or inserts a new one when there is none (`admin/cron/new`); then redirects to the list. Errors show the form again.
     */
    #[GameRoute('admin', 'admin/cron/{id}', 'cronjob', 'save', ['POST'], ['id' => '\d+'])]
    #[GameRoute('admin', 'admin/cron/new', 'cronjob', 'create', ['POST'])]
    public function save(): Response
    {
        $LNG = $this->state->LNG;
        $db = $this->db;
        $post_id = $this->input()->int('id');
        $post_name = $this->input()->string('name');
        $post_min = $this->checkPostData('min', 59);
        $post_hours = $this->checkPostData('hours', 23);
        $post_month = $this->checkPostData('month', 12);
        $post_dow = $this->checkPostData('dow', 6);
        $post_dom = $this->checkPostData('dom', 31);
        $post_class = $this->input()->string('class');
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

            return $this->redirectToRoute('admin_cronjob_show');
        }

        return $this->renderDetail($post_id, $error_msg);
    }

    /**
     * Deletes the cronjob and its log rows, then redirects to the list.
     */
    #[GameRoute('admin', 'admin/cron/{id}/delete', 'cronjob', 'delete', ['POST'], ['id' => '\d+'])]
    public function delete(): Response
    {
        $cronjobId = $this->input()->int('id');
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
        return $this->redirectToRoute('admin_cronjob_show');
    }

    /**
     * Locks the job (`lock` = hash of the current time) so the cron runner skips it, then redirects.
     */
    #[GameRoute('admin', 'admin/cron/{id}/lock', 'cronjob', 'lock', ['POST'], ['id' => '\d+'])]
    public function lock(): Response
    {
        $cronjobId = $this->input()->int('id');
        $db = $this->db;
        $db->update(
            "UPDATE %%CRONJOBS%% SET `lock` = MD5(UNIX_TIMESTAMP()) WHERE cronjobID = :cronjobID;",
            [
                ':cronjobID' => $cronjobId,
            ],
        );
        return $this->redirectToRoute('admin_cronjob_show');
    }

    /**
     * Removes the lock of the job (e.g. after a crashed run), then redirects.
     */
    #[GameRoute('admin', 'admin/cron/{id}/unlock', 'cronjob', 'unlock', ['POST'], ['id' => '\d+'])]
    public function unlock(): Response
    {
        $cronjobId = $this->input()->int('id');
        $db = $this->db;
        $db->update(
            "UPDATE %%CRONJOBS%% SET `lock` = NULL WHERE cronjobID = :cronjobID;",
            [
                ':cronjobID' => $cronjobId,
            ],
        );
        return $this->redirectToRoute('admin_cronjob_show');
    }

    /**
     * Runs the job once now (through Cronjob::execute), removes its lock, shows a message and redirects.
     */
    #[GameRoute('admin', 'admin/cron/{id}/run', 'cronjob', 'run', ['POST'], ['id' => '\d+'])]
    public function run(): Response
    {
        $cronjobId = $this->input()->int('id');
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
            return $this->messageResponse($e->getMessage(), $this->router->generate('admin_cronjob_show'));
        }

        return $this->redirectToRoute('admin_cronjob_show');
    }

    /**
     * Switches the job on or off (request: enable = 1/0 -> `isActive`), then redirects.
     */
    #[GameRoute('admin', 'admin/cron/{id}/enable', 'cronjob', 'enable', ['POST'], ['id' => '\d+'])]
    public function enable(): Response
    {
        $cronjobId = $this->input()->int('id');
        $db = $this->db;
        $db->update(
            "UPDATE %%CRONJOBS%% SET `isActive` = :isActive WHERE cronjobID = :cronjobID;",
            [
                ':isActive' => $this->input()->int('enable'),
                ':cronjobID' => $cronjobId,
            ],
        );
        return $this->redirectToRoute('admin_cronjob_show');
    }

    /**
     * List of all cronjobs with their schedule, state and the time of the last run (from CRONJOBS_LOG).
     */
    #[GameRoute('admin', 'admin/cron', 'cronjob')]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $db = $this->db;
        $data = $db->select("SELECT * FROM %%CRONJOBS%%;");
        if (!$data) {
            return $this->messageResponse($LNG['cronjob_no_data']);
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
                'month'         => $this->overviewTimes($CronjobRow['month'], 12),
                'dow'           => $this->overviewTimes($CronjobRow['dow'], 6),
                'class'         => $CronjobRow['class'],
                'lastExecution' => $lastExecutionTimestamp,
                'nextTime'      => $CronjobRow['nextTime'],
                'lock'          => !empty($CronjobRow['lock']),
            ];
        }
        return $this->render('admin/CronjobOverview.html.twig', [
            'CronjobArray' => $CronjobArray,
        ]);
    }

    /** Edit form of the job `id`. */
    #[GameRoute('admin', 'admin/cron/{id}', 'cronjob', 'detail', requirements: ['id' => '\d+'])]
    public function detail(): Response
    {
        return $this->renderDetail($this->input()->int('id'));
    }

    /** The empty form of a new job. */
    #[GameRoute('admin', 'admin/cron/new', 'cronjob', 'new')]
    public function new(): Response
    {
        return $this->renderDetail(0);
    }

    /**
     * Edit form of one job (or an empty form): lists the job classes found in src/Game/Cronjob/ and fills the schedule fields. The
     * optional $error_msg is shown above the form after a failed save.
     *
     * @param list<string>|null $error_msg
     */
    private function renderDetail(int $detail, ?array $error_msg = null): Response
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

        $context = ['avalibleCrons' => $avalibleCrons];

        if ($detail != 0) {
            $CronjobRow = $db->selectSingle(
                "SELECT * FROM %%CRONJOBS%% WHERE cronjobID = :cronjobID;",
                [
                    ':cronjobID' => $detail,
                ],
            );
            $context += [
                'id'           => $CronjobRow['cronjobID'],
                'name'         => isset($this->state->requestPost['name']) ? $this->input()->string('name') : $CronjobRow['name'],
                'min'          => isset($this->state->requestPost['min_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['min']) ? $this->input()->stringList('min') : $this->getCronjobTimes($CronjobRow['min'], 59)
                ),
                'hours'        => isset($this->state->requestPost['hours_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['hours']) ? $this->input()->stringList('hours') : $this->getCronjobTimes($CronjobRow['hours'], 23)
                ),
                'dom'          => isset($this->state->requestPost['dom_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['dom']) ? $this->input()->stringList('dom') : $this->getCronjobTimes($CronjobRow['dom'], 31)
                ),
                'month'        => isset($this->state->requestPost['month_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['month']) ? $this->input()->stringList('month') : $this->getCronjobTimes($CronjobRow['month'], 12)
                ),
                'dow'          => isset($this->state->requestPost['dow_all']) ? [0 => '*'] : (
                    isset($this->state->requestPost['dow']) ? $this->input()->stringList('dow') : $this->getCronjobTimes($CronjobRow['dow'], 6)
                ),
                'class'        => isset($this->state->requestPost['class']) ? $this->input()->string('class') : $CronjobRow['class'],
                'error_msg'    => $error_msg,
            ];
        } else {
            $context += [
                'id'           => 'add',
                'name'         => $this->input()->string('name'),
                'min'          => isset($this->state->requestPost['min_all']) ? [0 => '*'] : $this->input()->stringList('min'),
                'hours'        => isset($this->state->requestPost['hours_all']) ? [0 => '*'] : $this->input()->stringList('hours'),
                'dom'          => isset($this->state->requestPost['dom_all']) ? [0 => '*'] : $this->input()->stringList('dom'),
                'month'        => isset($this->state->requestPost['month_all']) ? [0 => '*'] : $this->input()->stringList('month'),
                'dow'          => isset($this->state->requestPost['dow_all']) ? [0 => '*'] : $this->input()->stringList('dow'),
                'class'        => $this->input()->string('class'),
                'error_msg'    => $error_msg,
            ];
        }

        return $this->render('admin/CronjobDetail.html.twig', $context);
    }

    /**
     * Schedule column for the overview: `*` stays a string, everything else is a list of values.
     *
     * @return list<int|string>|string
     */
    private function overviewTimes($row, $max): array|string
    {
        $times = $this->getCronjobTimes($row, $max);

        return is_string($times) && $times !== '*' ? [$times] : $times;
    }

    /**
     * @return array<mixed>
     */
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
        $all = $this->input()->int($column . '_all');
        if ($all) {
            return '*';
        }

        $post = $this->input()->stringList($column);
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
