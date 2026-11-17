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

/**
 * Achievement rules not tied to fights, queues or planets: expedition counts and black holes, recycling,
 * galaxy view, settings and shipyard milestones. Called from the missions/pages that produce the event.
 */
class MiscAchievement
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Check for achievement in relation to Expeditions
     * Slippy's blessing(12): Lose your first expedition to a black hole
     * Slippy's curse(14): Lose 10 expeditions to black holes
     *
     * @param int	 		$userId       	The id of the User
     */
    public function checkExpoAchievements($userId)
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $expo_count = $statsHandler->getValue($userId, AdvancedStatsEnum::EXPO_COUNT);
        $black_hole_count = $statsHandler->getValue($userId, AdvancedStatsEnum::EXPO_BLACK_HOLE);

        if ($expo_count == 0 && $black_hole_count == 1 && !$this->achievement()->checkAchievement($userId, 12)) {
            $this->achievement()->setAchievement($userId, 12);
        } elseif ($black_hole_count == 10 && !$this->achievement()->checkAchievement($userId, 14)) {
            $this->achievement()->setAchievement($userId, 14);
        }
    }

    /**
     * Check for achievement 16
     * Dude, Where's My Debris?: Have 250+ Recyclers return with no debris
     *
     * @param int	 		$userId       		The id of the User
     * @param array<mixed>			$collectedGoods     The resource array
     * @param int	 		$recCount       	The number of recyclers
     */
    public function checkRecAchievements($userId, $collectedGoods, $recCount)
    {
        if (!$this->achievement()->checkAchievement($userId, 16) && $collectedGoods[RESOURCE_METAL] == 0 && $collectedGoods[RESOURCE_CRYSTAL] == 0 && $recCount >= 250) {
            $this->achievement()->setAchievement($userId, 16);
        }
    }

    /**
     * Check for achievement 51
     * No clue, it's just there?: Drain your deuterium with the galaxy view
     *
     * @param int	 		$userId       		The id of the User
     */
    public function checkGalaxyAchievements($userId)
    {
        if (!$this->achievement()->checkAchievement($userId, 51)) {
            $this->achievement()->setAchievement($userId, 51);
        }
    }

    /**
     * Check for achievement 25
     * GDPR breach: Opt in to records sharing
     *
     * @param int	 		$userId       		The id of the User
     * @param int	 		$recordsOptIn       The records opt in
     */
    public function checkSettingsAchievements($userId, $recordsOptIn)
    {
        if (!$this->achievement()->checkAchievement($userId, 25) && $recordsOptIn == 1) {
            $this->achievement()->setAchievement($userId, 25);
        }
    }

    /**
     * Check for achievement 17
     * Overlord Defense: Build 5000 missile launchers in one go
     *
     * @param int	 		$userId       		The id of the User
     * @param int	 		$count       		The number of missile launchers
     * @param int	 		$element       		The id of the element
     */
    public function checkShipyardAchievements($userId, $count, $element)
    {
        if (!$this->achievement()->checkAchievement($userId, 17) && $count >= 5000 && $element == MISSILE_LAUNCHER) {
            $this->achievement()->setAchievement($userId, 17);
        }
    }
}
