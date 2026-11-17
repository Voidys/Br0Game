<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Controller\Game;

use App\Game\Tutorials\TutorialUtil;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tutorial actions (skip, claim, fulfil); every mode redirects back to the overview.
 */
#[\App\Controller\RequireModule('MODULE_TUTORIAL')]
class TutorialController extends AbstractGameController
{
    /**
     * Attempts to skip the current tutorial step goal if given by request, or the whole step, if able
     *
     * Called by button/link in shared.tutorial.tpl
     */
    #[GameRoute('game', 'game/tutorial/skip', 'tutorial', 'skip', ['POST'])]
    public function skip(): Response
    {
        $goal = $this->input()->int('goal');
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);

        if ($goal > 0) {
            $tutorialUtil->skipGoal($goal);
        } else {
            $tutorialUtil->skip();
        }

        return $this->redirectToRoute('game_overview_show');
    }

    /**
     * Skips the entire tutorial
     *
     * Called by button/link in shared.tutorial.tpl
     */
    #[GameRoute('game', 'game/tutorial/skip-all', 'tutorial', 'skipAll', ['POST'])]
    public function skipAll(): Response
    {
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);
        $tutorialUtil->skipAll();
        return $this->redirectToRoute('game_overview_show');
    }

    /**
     * Claims all rewards for the tutorial step, if any, and tags it accordingly
     *
     * Called by button/link in shared.tutorial.tpl
     */
    #[GameRoute('game', 'game/tutorial/claim', 'tutorial', 'claim', ['POST'])]
    public function claim(): Response
    {
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);
        $tutorialUtil->claim();
        return $this->redirectToRoute('game_overview_show');
    }

    /**
     * Attempts to tag the given goal as fullfilled
     *
     * Called by button/link in shared.tutorial.tpl
     *
     * Request: goal
     */
    #[GameRoute('game', 'game/tutorial/fulfill', 'tutorial', 'fullfill', ['POST'])]
    public function fullfill(): Response
    {
        $goal = $this->input()->int('goal');
        $tutorialUtil = new TutorialUtil($this->db, $this->configs, $this->state);

        if ($goal > 0) {
            $tutorialUtil->fullfillGoal($goal);
        }

        return $this->redirectToRoute('game_overview_show');
    }

    /**
     * Nothing to show, redirect to overview
     */
    #[GameRoute('game', 'game/tutorial', 'tutorial')]
    public function show(): Response
    {
        return $this->redirectToRoute('game_overview_show');
    }
}
