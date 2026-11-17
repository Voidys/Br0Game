<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Controller\Game;

use App\Game\Core\HTTP;
use App\Game\Tutorials\TutorialUtil;

/**
 * Tutorial actions (skip, claim, fulfil); every mode redirects back to the overview.
 */
#[\App\Routing\GamePage('game', 'tutorial')]
#[\App\Controller\RequireModule('MODULE_TUTORIAL')]
class TutorialController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Attempts to skip the current tutorial step goal if given by request, or the whole step, if able
     *
     * Called by button/link in shared.tutorial.tpl
     *
     * @return void
     */
    public function skip()
    {
        $goal = $this->http()->_GP('goal', 0);
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);

        if ($goal > 0) {
            $tutorialUtil->skipGoal($goal);
        } else {
            $tutorialUtil->skip();
        }

        $this->redirectTo('game?page=Overview');
    }

    /**
     * Skips the entire tutorial
     *
     * Called by button/link in shared.tutorial.tpl
     *
     * @return void
     */
    public function skipAll()
    {
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);
        $tutorialUtil->skipAll();
        $this->redirectTo('game?page=Overview');
    }

    /**
     * Claims all rewards for the tutorial step, if any, and tags it accordingly
     *
     * Called by button/link in shared.tutorial.tpl
     *
     * @return void
     */
    public function claim()
    {
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);
        $tutorialUtil->claim();
        $this->redirectTo('game?page=Overview');
    }

    /**
     * Attempts to tag the given goal as fullfilled
     *
     * Called by button/link in shared.tutorial.tpl
     *
     * @param integer $goal
     * @return void
     */
    public function fullfill()
    {
        $goal = $this->http()->_GP('goal', 0);
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);

        if ($goal > 0) {
            $tutorialUtil->fullfillGoal($goal);
        }

        $this->redirectTo('game?page=Overview');
    }

    /**
     * Nothing to show, redirect to overview
     *
     * @return void
     */
    public function show()
    {
        $this->redirectTo('game?page=Overview');
    }
}
