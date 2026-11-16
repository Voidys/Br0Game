<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\AccountUtil;
use App\Tests\Support\GameTestCase;

/**
 * The planned deletion date of an account is the next run of the cleaner cronjob (the job that really deletes
 * players and accounts), found by its class and not by a fixed cronjob id.
 */
final class AccountDeletionDateTest extends GameTestCase
{
    public function testDeletionDateFollowsTheCleanerCronjob(): void
    {
        $now = $this->state->runtime->timestamp;
        $this->db->update("UPDATE %%CRONJOBS%% SET `nextTime` = :t, `isActive` = 1 WHERE `class` = 'CleanerCronjob';", [':t' => $now + 1000]);
        $this->db->update("UPDATE %%CRONJOBS%% SET `nextTime` = :t, `isActive` = 1 WHERE `class` = 'DailyCronjob';", [':t' => $now + 5000]);

        $util = new AccountUtil($this->db, $this->configs, $this->state);

        $this->assertSame($now + 1000, $util->getDeletionDate($now, (object) ['del_user_manually' => 0]));
    }

    public function testNoDeletionDateWhileTheCleanerIsInactive(): void
    {
        $this->db->update("UPDATE %%CRONJOBS%% SET `isActive` = 0 WHERE `class` = 'CleanerCronjob';");

        $util = new AccountUtil($this->db, $this->configs, $this->state);

        $this->assertSame(0, $util->getDeletionDate($this->state->runtime->timestamp, (object) ['del_user_manually' => 0]));
    }
}
