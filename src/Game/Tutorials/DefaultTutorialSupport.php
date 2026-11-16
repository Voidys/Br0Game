<?php

/**
 * pr0game powered by steemnova
 * tutorial
 * (c) 2024 Hyman
 */

namespace App\Game\Tutorials;

/**
 * Standard step: goals are building levels, research levels or owned ships/defenses compared with the amounts of the goals.
 */
class DefaultTutorialSupport extends AbstractTutorialSupport
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
}
