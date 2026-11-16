<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Game\Tutorials;

/**
 * Step whose goal is to open an external link (the Discord invite); clicking the link reports the goal as fulfilled.
 */
class LinkTutorialSupport extends AbstractTutorialSupport
{
    public function __construct(
        \App\Game\Core\Database $db,
        \App\Game\Core\ConfigRegistry $configs,
        \App\Game\Core\Singleton $state,
        array $tutorialStep,
        array $USER,
    ) {
        parent::__construct($db, $configs, $state, $tutorialStep, $USER);
    }

    /** true while the admin has set a Discord URL (config `discord_url`); otherwise the link goal is skipped */
    private function discordConfigured(): bool
    {
        return trim((string) $this->configs->get()->discord_url) !== '';
    }

    /**
     * Rewards of the step; none without a Discord URL, because the goal is then fulfilled automatically and
     * not by the player's click.
     */
    public function listRewards(): array
    {
        return $this->discordConfigured() ? parent::listRewards() : [];
    }

    /** see listRewards() */
    public function hasRewards(): bool
    {
        return $this->discordConfigured() && parent::hasRewards();
    }

    /**
     * Returns the goal name/description and link functionality
     *
     * @param integer $elementID
     * @return string
     */
    protected function getGoalName(int $elementID): string
    {
        switch ($elementID) {
            case 1:
                $text = 'Discord';
                $link = trim((string) $this->configs->get()->discord_url);
                if ($link === '') {
                    // no community Discord configured: plain text, the goal is fulfilled automatically (listGoals())
                    return $text;
                }
                $goal = $this->state->runtime->httpPath.'game?page=tutorial&mode=fullfill&goal='.$elementID;
                // opens the link in a new tab and reports the goal as fulfilled (action "tutorial-link", scripts/game/tutorial.js)
                return '<a class="tutorial-link" href="'.htmlspecialchars($link, ENT_QUOTES).'" data-action="tutorial-link" data-goal="'.htmlspecialchars($goal, ENT_QUOTES).'" target="_blank" rel="noopener">'.$text.'</a>';

            default:
                return 'unknown';
        }
    }

    public function fullfillGoalByRequest(int $goal): void
    {
        $this->fullfillGoal($goal);
    }

    /**
     * Fullfills the given goal for the current user
     *
     * @param integer $goal
     * @return void
     */
    public function fullfillGoal(int $goal)
    {
        foreach ($this->tutorialGoals as $goalRow) {
            if ($goalRow['elementID'] == $goal) {
                $sql = "UPDATE %%USERS_TUTORIAL_GOAL%% SET `fullfilled` = 1 
                    WHERE `userID` = :userID AND `tutorialGoalID` = :tutorialGoalID";
                $this->db->update($sql, [
                    ':userID' => $this->user['id'],
                    ':tutorialGoalID' => $goalRow['id'],
                ]);
                return;
            }
        }
    }

    /**
     * List all enabeld and unskipped goals
     *
     * Can be disabled by table enty
     *
     * @return array
     */
    public function listGoals(): array
    {
        $goals = [];
        foreach ($this->tutorialGoals as $goalRow) {
            $elementID = $goalRow['elementID'];

            if ($goalRow['enabled'] == 0 ||
                $goalRow['skipped'] == 1) {
                continue;
            }

            $owned = 0;
            if ($goalRow['ammount'] == 1 && $goalRow['fullfilled'] == 1) {
                $owned = 1;
            }
            // no Discord configured: there is nothing to open, the goal counts as done so players never get stuck
            if (!$this->discordConfigured()) {
                $owned = (int) $goalRow['ammount'];
            }

            $goals[$elementID] = [
                'name'          => $this->getGoalName($elementID),
                'ammount'       => $goalRow['ammount'],
                'owned'         => $owned,
                'fullfilled'    => $goalRow['ammount'] <= $owned,
                'skippable'     => $goalRow['skippable'] == 1,
            ];
        }
        return $goals;
    }
}
