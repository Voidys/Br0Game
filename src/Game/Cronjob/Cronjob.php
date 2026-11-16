<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Game\Cronjob;

use Cron\CronExpression;
use DateTime;
use Exception;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\Singleton;
use App\Game\Core\Discord;

/**
 * Scheduler core for the `cronjobs` table (name, class, cron expression min/hours/dom/month/dow, isActive, nextTime,
 * lock). bin/console app:cron:run asks getNeedTodoExecutedJobs() for the due jobs every minute and runs each
 * through execute(); every task class is a CronjobTaskInterface service in this namespace.
 */
class Cronjob
{
    /** Cronjob tasks as Symfony services (tag app.game_cronjob), set by App\Runtime\GameKernel. */
    /**
     * @param ContainerInterface|null $tasks cronjob task services (container); without it the tasks are
     *                                       built by hand from the same services
     */
    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        #[\Symfony\Component\DependencyInjection\Attribute\AutowireLocator('app.game_cronjob')]
        private ?\Psr\Container\ContainerInterface $tasks = null,
    ) {}

    /**
     * Runs one job: only if it is active and not locked. Sets the lock (token = md5 of the request time), runs the task
     * (container service tagged app.game_cronjob, else built by hand), computes its next run time, releases the lock and
     * writes a row to cronjobs_log, then reports success to Discord. Task exceptions release the lock and are
     * reported to Discord (not rethrown, nextTime stays unchanged so the job is retried on the next run).
     * @throws Exception for an unknown, inactive or locked job id.
     */
    public function execute(int $cronjobID): void
    {

        $lockToken = md5($this->state->runtime->timestamp);

        $db = $this->db;

        $sql = 'SELECT class FROM %%CRONJOBS%% WHERE isActive = :isActive AND cronjobID = :cronjobId AND `lock` IS NULL;';

        $cronjobClassName = $db->selectSingle($sql, [
            ':isActive' => 1,
            ':cronjobId' => $cronjobID
        ], 'class');

        if (empty($cronjobClassName)) {
            throw new Exception(sprintf("Unknown cronjob %s or cronjob is deactive!", $cronjobID));
        }

        try {
            $db->update('UPDATE %%CRONJOBS%% SET `lock` = :lock WHERE cronjobID = :cronjobId;', [
                ':lock' => $lockToken,
                ':cronjobId' => $cronjobID
            ]);

            $cronjobClassName = 'App\\Game\\Cronjob\\' . $cronjobClassName;

            /** @var CronjobTaskInterface $cronjobObj */
            $cronjobObj = $this->tasks !== null && $this->tasks->has($cronjobClassName)
                ? $this->tasks->get($cronjobClassName)   // Symfony service (see config/services.yaml)
                : new $cronjobClassName($this->db, $this->configs, $this->state);
            $cronjobObj->run();

            $this->reCalculateCronjobs($cronjobID);

            $db->update('UPDATE %%CRONJOBS%% SET `lock` = NULL WHERE cronjobID = :cronjobId;', [
                ':cronjobId' => $cronjobID
            ]);

            $db->insert('INSERT INTO %%CRONJOBS_LOG%% SET `cronjobId` = :cronjobId, `executionTime` = :executionTime, `lockToken` = :lockToken', [
                ':cronjobId' => $cronjobID,
                ':executionTime' => $this->db->formatDate($this->state->runtime->timestamp),
                ':lockToken' => $lockToken
            ]);

            (new Discord($this->configs, $this->state))->sendLog($cronjobClassName, ['successful' => true]);
        } catch (Exception $exception) {
            // unlock cronjob
            $db->update('UPDATE %%CRONJOBS%% SET `lock` = :lock WHERE cronjobID = :cronjobId;', [
                ':lock' => null,
                ':cronjobId' => $cronjobID
            ]);

            (new Discord($this->configs, $this->state))->sendLog($cronjobClassName, null, $exception);
        }
    }

    /**
     * Ids of the active, unlocked jobs whose nextTime lies in the past.
     * @return int[]
     */
    public function getNeedTodoExecutedJobs(): array
    {
        $sql = 'SELECT cronjobID
		FROM %%CRONJOBS%%
		WHERE isActive = :isActive AND nextTime < :time AND `lock` IS NULL;';

        $cronjobResult = $this->db->select($sql, [
            ':isActive' => 1,
            ':time' => $this->state->runtime->timestamp
        ]);

        $cronjobList = [];

        foreach ($cronjobResult as $cronjobRow) {
            $cronjobList[] = $cronjobRow['cronjobID'];
        }

        return $cronjobList;
    }

    /**
     * Unix timestamp of the last logged run of the job with this `name` (cronjobs_log), false if it never ran.
     */
    public function getLastExecutionTime(string $cronjobName): int|false
    {
        $sql = 'SELECT MAX(executionTime) as executionTime FROM %%CRONJOBS_LOG%% INNER JOIN %%CRONJOBS%% USING(cronjobId) WHERE name = :cronjobName;';
        $lastTime = $this->db->selectSingle($sql, [
            ':cronjobName' => $cronjobName
        ], 'executionTime');

        if (empty($lastTime)) {
            return false;
        }

        return strtotime($lastTime);
    }

    /**
     * Sets nextTime of one job (or all when null) to the next run of its cron expression strictly after now (CronExpression already skips the current minute, so `* * * * *` runs every minute; the former +60 s made it skip one).
     */
    public function reCalculateCronjobs(?int $cronjobID = null): void
    {
        $db = $this->db;

        if (!empty($cronjobID)) {
            $sql = 'SELECT cronjobID, min, hours, dom, month, dow FROM %%CRONJOBS%% WHERE cronjobID = :cronjobId;';
            $cronjobResult = $db->select($sql, [
                ':cronjobId' => $cronjobID
            ]);
        } else {
            $sql = 'SELECT cronjobID, min, hours, dom, month, dow FROM %%CRONJOBS%%;';
            $cronjobResult = $db->select($sql);
        }

        $sql = 'UPDATE %%CRONJOBS%% SET nextTime = :nextTime WHERE cronjobID = :cronjobId;';

        foreach ($cronjobResult as $cronjobRow) {
            $cronTabString = implode(' ', [$cronjobRow['min'], $cronjobRow['hours'], $cronjobRow['dom'], $cronjobRow['month'], $cronjobRow['dow']]);

            $cron = new CronExpression($cronTabString);
            $nextTime = $cron->getNextRunDate(new DateTime('@' . $this->state->runtime->timestamp))->getTimestamp();

            $db->update($sql, [
                ':nextTime' => $nextTime,
                ':cronjobId' => $cronjobRow['cronjobID'],
            ]);
        }
    }
}
