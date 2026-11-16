<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Tutorials\LinkTutorialSupport;
use App\Tests\Support\GameTestCase;

/**
 * The Discord tutorial step (a link goal): with a configured `discord_url` it keeps working as before (goal open
 * until the player clicks the link, reward granted); without one the goal counts as fulfilled automatically,
 * shows no link and grants no reward, so players are never stuck on a link they cannot open.
 */
final class LinkTutorialTest extends GameTestCase
{
    private const STEP_ID = 3;

    /** a player with the Discord step's goal row and one reward row (1000 metal) */
    private function support(string $discordUrl): LinkTutorialSupport
    {
        [$user] = $this->createPlayer();
        $goalId = (int) $this->db->selectSingle('SELECT id FROM %%TUTORIAL_GOALS%% WHERE tutorialID = :t;', [':t' => self::STEP_ID], 'id');
        $this->db->insert('INSERT INTO %%USERS_TUTORIAL%% (userID, tutorialID) VALUES (:u, :t);', [':u' => $user['id'], ':t' => self::STEP_ID]);
        $this->db->insert('INSERT INTO %%USERS_TUTORIAL_GOAL%% (userID, tutorialGoalID) VALUES (:u, :g);', [':u' => $user['id'], ':g' => $goalId]);
        $this->db->delete('DELETE FROM %%TUTORIAL_REWARDS%% WHERE tutorialID = :t;', [':t' => self::STEP_ID]);
        $this->db->insert('INSERT INTO %%TUTORIAL_REWARDS%% (tutorialID, name, elementID, ammount, enabled) VALUES (:t, :n, 901, 1000, 1);', [':t' => self::STEP_ID, ':n' => 'metal']);
        $this->configs->get()->discord_url = $discordUrl;

        return new LinkTutorialSupport($this->db, $this->configs, $this->state, ['id' => self::STEP_ID], $user);
    }

    public function testWithDiscordUrlTheGoalNeedsTheClickAndRewardsAreKept(): void
    {
        $support = $this->support('https://chat.example.org/invite');

        $goal = $support->listGoals()[1];
        self::assertFalse($support->isFullfilled());
        self::assertFalse($goal['fullfilled']);
        self::assertStringContainsString('href="https://chat.example.org/invite"', $goal['name']);
        self::assertStringContainsString('data-action="tutorial-link"', $goal['name']);
        self::assertTrue($support->hasRewards());

        $support->fullfillGoal(1);
        $after = new LinkTutorialSupport($this->db, $this->configs, $this->state, ['id' => self::STEP_ID], $this->loadUser((int) $this->userIdOf($support)));
        self::assertTrue($after->isFullfilled());
    }

    public function testWithoutDiscordUrlTheGoalIsFulfilledWithoutLinkOrReward(): void
    {
        $support = $this->support('');

        $goal = $support->listGoals()[1];
        self::assertTrue($goal['fullfilled']);
        self::assertTrue($support->isFullfilled());
        self::assertSame('Discord', $goal['name']);
        self::assertSame([], $support->listRewards());
        self::assertFalse($support->hasRewards());
    }

    private function userIdOf(LinkTutorialSupport $support): int
    {
        return (int) (new \ReflectionProperty(\App\Game\Tutorials\AbstractTutorialSupport::class, 'user'))->getValue($support)['id'];
    }
}
