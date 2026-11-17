<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Core\Maintenance;
use App\Tests\Support\GameTestCase;

/**
 * Maintenance::checkDBUpgrade() remembers "no migration pending" in var/game/cache.dbstatus.php so a page request
 * skips the `system` query; pending migrations are never cached.
 */
final class DbUpgradeCheckCacheTest extends GameTestCase
{
    private string $cacheFile;
    private ?string $backup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->cacheFile = $this->state->runtime->cachePath . 'cache.dbstatus.php';
        $this->backup = is_file($this->cacheFile) ? (string) file_get_contents($this->cacheFile) : null;
        @unlink($this->cacheFile);
    }

    protected function tearDown(): void
    {
        if ($this->backup !== null) {
            file_put_contents($this->cacheFile, $this->backup);
        } else {
            @unlink($this->cacheFile);
        }
        parent::tearDown();
    }

    public function testUpToDateDatabaseIsRememberedAndSkipsTheQuery(): void
    {
        $maintenance = new Maintenance($this->db, $this->configs, $this->state);
        $this->assertFalse($maintenance->checkDBUpgrade());
        $this->assertFileExists($this->cacheFile);

        // a row removed behind the cache is not noticed (the key did not change), proving the query is skipped
        $latest = (int) $this->db->selectSingle('SELECT MAX(dbVersion) AS v FROM %%SYSTEM%%;', [], 'v');
        $this->db->delete('DELETE FROM %%SYSTEM%% WHERE dbVersion = :v;', [':v' => $latest]);
        $this->assertFalse($maintenance->checkDBUpgrade());
    }

    public function testPendingMigrationIsReportedAndNotCached(): void
    {
        $latest = (int) $this->db->selectSingle('SELECT MAX(dbVersion) AS v FROM %%SYSTEM%%;', [], 'v');
        $this->db->delete('DELETE FROM %%SYSTEM%% WHERE dbVersion = :v;', [':v' => $latest]);

        $maintenance = new Maintenance($this->db, $this->configs, $this->state);
        $this->assertTrue($maintenance->checkDBUpgrade());
        $this->assertFileDoesNotExist($this->cacheFile);
    }
}
