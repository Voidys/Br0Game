<?php

/**
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Reflexrecon
 * @copyright 2023 Reflexrecon
 * @licence MIT
 * @version 1.8.0
 */

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Achievement;
use App\Game\Core\Singleton;

/**
 * Achievements overview of the player.
 */
#[\App\Routing\GamePage('game', 'achievements')]
class AchievementsController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    /**
     * Achievement list with the unlock date of those the player has earned (not-yet-implemented achievements are hidden) and the unlocked count.
     */
    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;

        $allAchievements    = $this->achievement()->getAchievementList();
        $userAchievements   = $this->achievement()->getUserAchievements($USER['id']);

        // unlock date per achievement id
        $unlocked = [];
        foreach ($userAchievements as $userAchievement) {
            $unlocked[(int) $userAchievement['achievementID']] = (int) $userAchievement['date'];
        }

        $achievements = [];
        foreach ($allAchievements as $achievement) {
            $id = (int) $achievement['id'];
            if (in_array($id, NOT_IMPLEMENTED_ACHIEVEMENTS)) {
                continue;
            }
            $date = $unlocked[$id] ?? 0;
            $achievements[] = [
                'id'       => $id,
                'unlocked' => isset($unlocked[$id]),
                'date'     => $date > 0 ? $this->viewHelpers()->formatDate($LNG['php_dateformat'], $date, $USER['timezone']) : '',
            ];
        }
        $unlockedCount = count(array_filter($achievements, static fn(array $a): bool => $a['unlocked']));

        $this->assign([
            'achievements'          => $achievements,
            'unlockedCount'         => $unlockedCount,
            'totalCount'            => count($achievements),
        ]);

        return $this->render('game/page.achievements.default.html.twig');
    }
}
