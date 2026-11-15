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
            $nextTime = $cron->getNextRunDate(new DateTime('@' . $this->state->runtime->timestamp + 60))->getTimestamp();

            $db->update($sql, [
                ':nextTime' => $nextTime,
                ':cronjobId' => $cronjobRow['cronjobID'],
            ]);
        }
    }
}
