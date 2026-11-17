<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Cronjob\Cronjob;
use App\Tests\Support\GameTestCase;

/**
 * The time of the last logged run of a job by name (the statistics page shows it).
 */
final class CronjobLastExecutionTest extends GameTestCase
{
    public function testLastExecutionIsTheNewestLoggedRunOfTheJob(): void
    {
        $jobId = (int) $this->db->selectSingle("SELECT cronjobID FROM %%CRONJOBS%% WHERE name = 'statistic'", [], 'cronjobID');
        $otherId = (int) $this->db->selectSingle("SELECT cronjobID FROM %%CRONJOBS%% WHERE name <> 'statistic' LIMIT 1", [], 'cronjobID');
        $this->db->delete('DELETE FROM %%CRONJOBS_LOG%% WHERE cronjobId IN (:a, :b);', [':a' => $jobId, ':b' => $otherId]);
        foreach ([[$jobId, '2026-01-02 03:04:05'], [$jobId, '2026-03-04 05:06:07'], [$jobId, '2026-02-01 00:00:00'], [$otherId, '2027-01-01 00:00:00']] as [$id, $time]) {
            $this->db->insert('INSERT INTO %%CRONJOBS_LOG%% SET cronjobId = :id, executionTime = :t, lockToken = :l', [':id' => $id, ':t' => $time, ':l' => 'x']);
        }

        $cron = new Cronjob($this->db, $this->configs, $this->state);

        $this->assertSame(strtotime('2026-03-04 05:06:07'), $cron->getLastExecutionTime('statistic'));
    }

    public function testNeverRunOrUnknownJobGivesFalse(): void
    {
        $jobId = (int) $this->db->selectSingle("SELECT cronjobID FROM %%CRONJOBS%% WHERE name = 'statistic'", [], 'cronjobID');
        $this->db->delete('DELETE FROM %%CRONJOBS_LOG%% WHERE cronjobId = :id;', [':id' => $jobId]);
        $cron = new Cronjob($this->db, $this->configs, $this->state);

        $this->assertFalse($cron->getLastExecutionTime('statistic'));
        $this->assertFalse($cron->getLastExecutionTime('no-such-job'));
    }
}
