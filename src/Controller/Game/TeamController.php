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

use Symfony\Component\HttpFoundation\Response;

/**
 * Team page with links to the player cards of the game team.
 */
#[\App\Routing\GamePage('game.php', 'team')]
#[\App\Routing\GamePage('game.php', 'team', uniPrefix: true)]
class TeamController extends AbstractGameController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        // the team is maintained in the admin team section (team_members), the same list as the public team page
        $this->assign([
            'members' => $this->db->select('SELECT name, position, description, contact FROM %%TEAM_MEMBERS%% ORDER BY sort ASC, id ASC;'),
        ]);

        return $this->render('game/page.team.default.html.twig');
    }
}
