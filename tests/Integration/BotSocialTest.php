<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Bots\BotSocial;
use App\Tests\Support\GameTestCase;

/**
 * Runs the bot social turn (reply, buddy, alliance, proactive message steps are rolled by chance) many times on the test
 * database. The steps swallow their own errors into the bot log, so the test checks the log for errors.
 */
final class BotSocialTest extends GameTestCase
{
    public function testSocialTurnsRunWithoutErrors(): void
    {
        [$bot] = $this->createPlayer();
        [$human] = $this->createPlayer();
        $social = self::container()->get(BotSocial::class);
        $this->state->USER = $bot;
        $row = ['user_id' => $bot['id'], 'universe' => 1, 'persona' => null];

        for ($i = 0; $i < 40; $i++) {
            $social->act($row, $bot, []);
        }

        $errors = (int) $this->db->selectSingle("SELECT COUNT(*) AS c FROM %%BOT_LOG%% WHERE user_id = :u AND action = 'social' AND detail LIKE '%:%';", [':u' => $bot['id']], 'c');
        $this->assertSame(0, $errors, 'no social step failed');
    }
}
