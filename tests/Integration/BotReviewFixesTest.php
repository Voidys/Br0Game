<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Command\BotsFastForwardCommand;
use App\Game\Bots\BotEngine;
use App\Game\Bots\BotProfiles;
use App\Game\Bots\BotSocialMessaging;
use App\Game\Bots\BotSpawner;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Runtime\GameKernel;
use App\Tests\Support\GameTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Regression tests of the second review round for the bots: social targets (staff, vacation, banned are never greeted),
 * the fast-forward guard (not outside dev/test, human fleets untouched) and spawning (count cap under a lock).
 */
final class BotReviewFixesTest extends GameTestCase
{
    /** @param array<string, mixed> $bot */
    private function greet(array $bot): bool
    {
        $messaging = self::container()->get(BotSocialMessaging::class);
        $ctx = [
            'bot' => (int) $bot['id'],
            'uni' => 1,
            'me' => ['id' => $bot['id'], 'username' => $bot['username'], 'galaxy' => $bot['galaxy'], 'system' => $bot['system'], 'planet' => $bot['planet'], 'ally_id' => 0],
            'row' => [],
            'persona' => ['language' => 'en'],
            'profile' => ['message_chance' => 100],
        ];

        return $messaging->stepMessage($ctx);
    }

    public function testGreetingsSkipStaffVacationAndBannedPlayers(): void
    {
        [$bot] = $this->createPlayer();
        $online = time();
        $this->createPlayer(['authlevel' => 1, 'onlinetime' => $online]);
        $this->createPlayer(['urlaubs_modus' => 1, 'onlinetime' => $online]);
        $this->createPlayer(['banaday' => $online + 86400, 'onlinetime' => $online]);

        $this->assertFalse($this->greet($bot), 'staff, vacation and banned players are no social targets');

        [$normal] = $this->createPlayer(['onlinetime' => $online]);
        $this->assertTrue($this->greet($bot), 'a normal neighbour is still greeted');
        $greeted = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%MESSAGES%% WHERE message_owner = :id;', [':id' => $normal['id']], 'c');
        $this->assertSame(1, $greeted);
    }

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
