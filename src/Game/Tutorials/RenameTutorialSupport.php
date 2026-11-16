<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Game\Tutorials;

use App\Game\Core\Singleton;

class RenameTutorialSupport extends AbstractTutorialSupport
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
     * Returns the goal name/description and link funktionality
     *
     * @param integer $elementID
     * @return string
     */
    protected function getGoalName(int $elementID): string
    {
        $LNG = $this->state->LNG;
        $LNG->includeData(['TUTORIAL']);

        return $LNG['tutorial_goal']['step6'][$elementID];
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

            if ($goalRow['enabled'] == 0 || $goalRow['skipped'] == 1) {
                continue;
            }

            $owned = 0;
            if ($goalRow['fullfilled'] == 1) {
                $owned = $goalRow['ammount'];
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

    /**
     * Indicates whether all goals are fullfilled
     *
     * @return boolean
     */
    public function isFullfilled(): bool
    {
        $LNG = $this->state->LNG;

        $goals = $this->listGoals();
        $fullfilled = true;

        foreach ($goals as $elementID => $goalRow) {
            if ($elementID == 1 && $goalRow['fullfilled'] != 1) {
                if ($this->planet['name'] != $LNG['fcm_mainplanet']) {
                    $this->fullfillGoal($elementID);
                    $fullfilled = $fullfilled && true;
                } else {
                    $fullfilled = false;
                }
            }

            if ($elementID == 2 && $goalRow['fullfilled'] != 1) {
                $sql = 'SELECT * FROM %%BUDDY%% WHERE sender = :userID;';
                $request = $this->db->selectSingle($sql, [
                    ':userID' => $this->user['id'],
                ]);
                if (!empty($request)) {
                    $this->fullfillGoal($elementID);
                    $fullfilled = $fullfilled && true;
                } else {
                    $fullfilled = false;
                }
            }

            if ($elementID == 3 && $goalRow['fullfilled'] != 1) {
                $sql = 'SELECT * FROM %%ALLIANCE_REQUEST%% WHERE userID = :userID;';
                $request = $this->db->selectSingle($sql, [
                    ':userID' => $this->user['id'],
                ]);
                if (!empty($request) || $this->user['ally_id'] > 0) {
                    $this->fullfillGoal($elementID);
                    $fullfilled = $fullfilled && true;
                } else {
                    $fullfilled = false;
                }
            }
        }

        return $fullfilled;
    }

    /**
     * Fullfills the given goal for the current user
     *
     * @param integer $goal
     * @return void
     */
    public function fullfillGoal(int $goal)
    {
        foreach ($this->tutorialGoals as &$goalRow) {
            if ($goalRow['elementID'] == $goal) {
                $sql = "UPDATE %%USERS_TUTORIAL_GOAL%% SET `fullfilled` = 1 
                    WHERE `userID` = :userID AND `tutorialGoalID` = :tutorialGoalID";
                $this->db->update($sql, [
                    ':userID' => $this->user['id'],
                    ':tutorialGoalID' => $goalRow['id'],
                ]);
                $goalRow['fullfilled'] = 1;
                return;
            }
        }
    }
}
