<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Tests\Support\GameTestCase;

/**
 * The honor ranking reads the fight counters in the same query (it ran two queries per listed player, about 1.2
 * queries per player of the universe for every battle): counts are attached, missing counters are 0, ties on honor
 * points are broken by honorable fights (more first), then dishonorable fights (fewer first).
 */
final class HonorListTest extends GameTestCase
{
    public function testTopListCarriesFightCountersAndBreaksTies(): void
    {
        $stats = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        // far above anything other fixtures could hold, so these three are the top of the universe
        $hp = 1_000_000_000;
        [$a] = $this->createPlayer();
        [$b] = $this->createPlayer();
        [$c] = $this->createPlayer();
        $stats->setValue((int) $a['id'], AdvancedStatsEnum::HONORPOINTS, $hp);
        $stats->setValue((int) $b['id'], AdvancedStatsEnum::HONORPOINTS, $hp);
        $stats->setValue((int) $c['id'], AdvancedStatsEnum::HONORPOINTS, $hp + 1);
        $stats->setValue((int) $a['id'], AdvancedStatsEnum::HONORABLE_FIGHTS, 2);
        $stats->setValue((int) $a['id'], AdvancedStatsEnum::DISHONORABLE_FIGHTS, 1);
        $stats->setValue((int) $b['id'], AdvancedStatsEnum::HONORABLE_FIGHTS, 5);

        $list = $stats->getHonorList(1, 3, 'top');

        $this->assertSame([(int) $c['id'], (int) $b['id'], (int) $a['id']], array_map(static fn(array $row): int => (int) $row['userId'], $list));
        $this->assertSame([0, 5, 2], array_column($list, 'honorable_fights'));
        $this->assertSame([0, 0, 1], array_column($list, 'dishonorable_fights'));
    }

    public function testLowListStartsWithTheWorst(): void
    {
        $stats = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        [$a] = $this->createPlayer();
        [$b] = $this->createPlayer();
        $stats->setValue((int) $a['id'], AdvancedStatsEnum::HONORPOINTS, -1_000_000_000);
        $stats->setValue((int) $b['id'], AdvancedStatsEnum::HONORPOINTS, -999_999_999);
        $stats->setValue((int) $b['id'], AdvancedStatsEnum::DISHONORABLE_FIGHTS, 3);

        $list = $stats->getHonorList(1, 2, 'low');

        $this->assertSame([(int) $a['id'], (int) $b['id']], array_map(static fn(array $row): int => (int) $row['userId'], $list));
        $this->assertSame([0, 3], array_column($list, 'dishonorable_fights'));
    }
}
