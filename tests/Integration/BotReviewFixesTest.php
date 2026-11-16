<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\BotsFastForwardCommand;
use App\Game\Bots\BotEngine;
use App\Game\Bots\BotProfiles;
use App\Game\Bots\BotSpawner;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Runtime\GameKernel;
use App\Tests\Support\GameTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression tests of the second review round for the bots:
 * the fast-forward guard (not outside dev/test, human fleets untouched) and spawning (count cap under a lock).
 */
final class BotReviewFixesTest extends GameTestCase
{
    public function testFastForwardRefusesOutsideDevAndTestEvenWithForce(): void
    {
        $container = self::container();
        $command = new BotsFastForwardCommand(
            $container->get(GameKernel::class),
            $container->get(BotEngine::class),
            $container->get(Database::class),
            $container->get(ConfigRegistry::class),
            'prod',
        );
        $tester = new CommandTester($command);

        $this->assertSame(1, $tester->execute(['--force' => true]));
        $this->assertStringContainsString('Only in the dev or test environment', $tester->getDisplay());
    }

    public function testSpawnHonoursTheMaxCountAndCreatesTheBotRow(): void
    {
        $container = self::container();
        $spawner = $container->get(BotSpawner::class);
        $profileId = (int) $container->get(BotProfiles::class)->all()[0]['id'];
        $existing = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%BOT_PLAYERS%% WHERE universe = 1;', [], 'c');
        $this->setUniverseConfig(['bots_max_count' => $existing + 1]);

        $created = $spawner->spawn(1, $profileId, 5);

        try {
            $this->assertCount(1, $created, 'capped at bots_max_count');
            $this->assertSame(1, (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%BOT_PLAYERS%% WHERE user_id = :id;', [':id' => $created[0]], 'c'));
            $this->assertCount(0, $spawner->spawn(1, $profileId, 5), 'no room left');
            // the spawn lock is released again
            $this->assertSame(1, (int) $this->db->selectSingle('SELECT IS_FREE_LOCK(:n) AS f;', [':n' => $this->db->getDatabaseName() . ':bots_spawn_1'], 'f'));
        } finally {
            foreach ($created as $id) {
                $spawner->remove((int) $id);
            }
        }
    }
}
