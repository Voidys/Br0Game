<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Tests\Support\GameTestCase;

/**
 * The counters are integers: fractional amounts (debris values are floats) are cut off toward zero without PHP
 * deprecations, and the counter keeps adding up.
 */
final class AdvancedStatsIncrementTest extends GameTestCase
{
    public function testFractionalAmountsAreCutOffExplicitly(): void
    {
        [$user] = $this->createPlayer();
        $stats = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        $stats->increment((int) $user['id'], AdvancedStatsEnum::KBMETAL, 12.9);
        $stats->increment((int) $user['id'], AdvancedStatsEnum::KBMETAL, '3.7');
        $stats->increment((int) $user['id'], AdvancedStatsEnum::KBMETAL, 5);

        $this->assertEquals(20, $stats->getValue((int) $user['id'], AdvancedStatsEnum::KBMETAL));
    }
}
