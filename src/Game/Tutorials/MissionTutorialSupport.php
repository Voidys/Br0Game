<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Game\Tutorials;

use App\Game\Core\Singleton;

class MissionTutorialSupport extends AbstractTutorialSupport
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
     * Returns the goal name/description and link funktionality
     *
     * @param integer $elementID
     * @return string
     */
    protected function getGoalName(int $elementID): string
    {
        $LNG = & $this->state->LNG;
        $LNG->includeData(['TUTORIAL']);
        $reslist = & $this->state->reslist;

        if (in_array($elementID, $reslist['fleet'])) {
            return parent::getGoalName($elementID);
        }
        return $LNG['tutorial_goal']['mission'][$elementID];
    }

    /**
     * Indicates whether all goals are fullfilled
     *
     * @return boolean
     */
    public function isFullfilled(): bool
    {
        $reslist = & $this->state->reslist;

        $goals = $this->listGoals();
        $fullfilled = true;

        foreach ($goals as $elementID => $goalRow) {
            if (in_array($elementID, $reslist['fleet'])) {
                $fullfilled = $fullfilled && $goalRow['fullfilled'];
            } elseif ($goalRow['fullfilled'] != 1) {
                $sql = "SELECT COUNT(`id`) as amount FROM %%LOG_FLEETS%%
                    WHERE `mission` = :mission 
                      AND (`return_time` <= :end_time OR (`arrival_time` <= :end_time AND `mission` = :colo)) 
                      AND `hasCanceled` = 0 
                      AND `owner` = :userID;";
                $amount = $this->db->selectSingle($sql, [
                    ':mission'  => $elementID,
                    ':end_time' => $this->state->runtime->timestamp,
                    ':colo'     => MISSION_COLONISATION,
                    ':userID'   => $this->user['id'],
                ], 'amount');

                if ($amount > 0) {
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
