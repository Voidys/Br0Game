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
                $link = 'https://discord.gg/jhYYN3yuat';
                $goal = $this->state->runtime->httpPath.'game?page=tutorial&mode=fullfill&goal='.$elementID;
                // opens the link in a new tab and reports the goal as fulfilled (action "tutorial-link", scripts/game/tutorial.js)
                return '<a class="tutorial-link" href="'.$link.'" data-action="tutorial-link" data-goal="'.htmlspecialchars($goal, ENT_QUOTES).'" target="_blank" rel="noopener">'.$text.'</a>';

            default:
                return 'unknown';
        }
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
