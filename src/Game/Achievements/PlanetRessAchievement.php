<?php

/**
 * pr0game powered by steemnova
 * achievements
 * (c) 2022 reflexrecon
 */

namespace App\Game\Achievements;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Achievement;
use App\Game\Core\Singleton;

/**
 * Achievement rules for what a planet or player has built: research levels, built ships and moon storage levels;
 * called from ResourcePersistence::savePlanet() when queued elements finish.
 */
class PlanetRessAchievement
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Check for achievements in relation to Technology researches
     *
     * @param int			$element       	The id of the element
     * @param int	 		$userId       	The id of the User
     * @param int			$level       	The level of the element
     */
    public function checkPlanetRessAchievementsResearch($element, $userId, $level)
    {
        $achievementId = null;
        if ($element == INTERGALACTIC_TECH && $level == 1) {
            $achievementId = 27;
        } elseif ($element == DEFENCE_TECH) {
            $defenceAchievements = [3 => 19, 6 => 20, 9 => 21, 12 => 22, 15 => 23];
            $achievementId = $defenceAchievements[$level] ?? null;
        } elseif ($element == GRAVITON_TECH && $level == 2) {
            $achievementId = 5;
        } elseif ($element == LASER_TECH && $level == 12) {
            $achievementId = 24;
        } elseif ($element == IONIC_TECH && $level == 10) {
            $achievementId = 4;
        }

        if ($achievementId !== null && !$this->achievement()->checkAchievement($userId, $achievementId)) {
            $this->achievement()->setAchievement($userId, $achievementId);
        }
    }

    /**
     * Check for achievements in relation to Build ships
     * Build your first Deathstar (Achievement 9)
     * Build your first Deathstar as your first battleship (Achievement 52)
     *
     * @param int			$element       	The id of the element
     * @param int	 		$userId       	The id of the User
     */
    public function checkPlanetRessAchievementsShips($element, $userId)
    {
        if ($element == SHIP_RIP && !$this->achievement()->checkAchievement($userId, 9)) {
            $this->achievement()->setAchievement($userId, 9);
        }
        if ($element == SHIP_RIP && !$this->achievement()->checkAchievement($userId, 52)) {
            $reslist = $this->state->reslist;
            $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

            $count = 0;
            foreach ($reslist['ship_type']['military'] as $elementId) {
                $count += $statsHandler->getValue($userId, AdvancedStatsEnum::getBuild($elementId));
            }

            if ($count == 0) {
                $this->achievement()->setAchievement($userId, 52);
            }
        }
    }

    /**
     * Check for achievement 28
     * Alternate gameplay: Build Metal, Crystal and Deut storages to Level 3 each on a Moon
     *
     * @param array			$PLANET       	The planet array
     * @param array			$resource       The resource array
     * @param int	 		$userId       	The id of the User
     */
    public function checkPlanetRessAchievements28($PLANET, $resource, $userId)
    {
        if (!$this->achievement()->checkAchievement($userId, 28) && $PLANET[$resource[METAL_STORE]] >= 3 && $PLANET[$resource[CRYSTAL_STORE]] >= 3 && $PLANET[$resource[DEUTERIUM_STORE]] >= 3) {
            $this->achievement()->setAchievement($userId, 28);
        }
    }
}
