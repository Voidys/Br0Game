<?php

/**
 *  2Moons
 *   by Timo_Ka 2023
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package pr0game
 * @copyright 2023 Timo_Ka
 * @licence MIT
 * @version 1.8.0
 * @link https://codeberg.org/pr0game/pr0game
 */

namespace App\Controller\Game;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Team page with links to the player cards of the game team.
 */
class TeamController extends AbstractGameController
{
    use \App\Controller\Shared\TeamPage {
        show as protected renderTeam;
    }

    #[GameRoute('game', 'game/team', 'team')]
    public function show(): Response
    {
        return $this->renderTeam();
    }
}
