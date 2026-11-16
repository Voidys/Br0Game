<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Game\Tutorials\MissionTutorialSupport;
use App\Game\Tutorials\TutorialUtil;
use App\Tests\Support\GameTestCase;

/**
 * A request (TutorialController::fullfill -> TutorialUtil::fullfillGoal) must not fulfil goals that are server facts:
 * a mission goal without a matching LOG_FLEETS row stays open and claiming grants nothing.
 */
final class TutorialFullfillRequestTest extends GameTestCase
{
    public function testRequestCannotFullfillAMissionGoal(): void
    {
        [$user] = $this->createPlayer();
        $step = $this->db->selectSingle('SELECT * FROM %%TUTORIALS%% WHERE support_class = :c ORDER BY id LIMIT 1;', [':c' => 'MissionTutorialSupport']);
        self::assertNotEmpty($step, 'seeded mission step');
        $stepId = (int) $step['id'];
        $this->db->update('UPDATE %%TUTORIALS%% SET enabled = 1 WHERE id = :t;', [':t' => $stepId]);
        $this->db->delete('DELETE FROM %%USERS_TUTORIAL%% WHERE userID = :u;', [':u' => $user['id']]);
        $this->db->insert('INSERT INTO %%USERS_TUTORIAL%% (userID, tutorialID) VALUES (:u, :t);', [':u' => $user['id'], ':t' => $stepId]);
        $goals = $this->db->select('SELECT id, elementID FROM %%TUTORIAL_GOALS%% WHERE tutorialID = :t;', [':t' => $stepId]);
        self::assertNotEmpty($goals);
        foreach ($goals as $goal) {
            $this->db->insert('INSERT INTO %%USERS_TUTORIAL_GOAL%% (userID, tutorialGoalID) VALUES (:u, :g);', [':u' => $user['id'], ':g' => $goal['id']]);
        }
        $this->db->delete('DELETE FROM %%LOG_FLEETS%% WHERE owner = :u;', [':u' => $user['id']]);
        $this->state->USER = $user;

        $util = new TutorialUtil($this->db, $this->configs, $this->state);
        $this->makeCurrent($user, $stepId);
        foreach ($goals as $goal) {
            $util->fullfillGoal((int) $goal['elementID']);
        }

        $flagged = (int) $this->db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS_TUTORIAL_GOAL%% WHERE userID = :u AND fullfilled = 1;', [':u' => $user['id']], 'c');
        self::assertSame(0, $flagged, 'a request does not set the fullfilled flag');
        $support = new MissionTutorialSupport($this->db, $this->configs, $this->state, $step, $user);
        self::assertFalse($support->isFullfilled());
    }

    public function testRequestStillFullfillsALinkGoal(): void
    {
        [$user] = $this->createPlayer();
        $stepId = 3;
        $goalId = (int) $this->db->selectSingle('SELECT id FROM %%TUTORIAL_GOALS%% WHERE tutorialID = :t;', [':t' => $stepId], 'id');
        $this->db->update('UPDATE %%TUTORIALS%% SET enabled = 1 WHERE id = :t;', [':t' => $stepId]);
        $this->db->delete('DELETE FROM %%USERS_TUTORIAL%% WHERE userID = :u;', [':u' => $user['id']]);
        $this->db->insert('INSERT INTO %%USERS_TUTORIAL%% (userID, tutorialID) VALUES (:u, :t);', [':u' => $user['id'], ':t' => $stepId]);
        $this->db->insert('INSERT INTO %%USERS_TUTORIAL_GOAL%% (userID, tutorialGoalID) VALUES (:u, :g);', [':u' => $user['id'], ':g' => $goalId]);
        $this->configs->get()->discord_url = 'https://chat.example.org/invite';
        $this->state->USER = $user;

        $util = new TutorialUtil($this->db, $this->configs, $this->state);
        $this->makeCurrent($user, $stepId);
        $util->fullfillGoal(1);

        $flagged = (int) $this->db->selectSingle('SELECT fullfilled FROM %%USERS_TUTORIAL_GOAL%% WHERE userID = :u AND tutorialGoalID = :g;', [':u' => $user['id'], ':g' => $goalId], 'fullfilled');
        self::assertSame(1, $flagged);
    }

    /** the TutorialUtil constructor adds rows for all enabled steps; complete the others so the given step is the current one */
    /** @param array<string, mixed> $user */
    private function makeCurrent(array $user, int $stepId): void
    {
        $this->db->update('UPDATE %%USERS_TUTORIAL%% SET completed = 1 WHERE userID = :u AND tutorialID != :t;', [':u' => $user['id'], ':t' => $stepId]);
    }
}
