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

namespace App\Controller\Login;

use Symfony\Component\HttpFoundation\Response;

/** Public team page (members from the admin team section). */
#[\App\Routing\GamePage('index.php', 'team')]
#[\App\Routing\GamePage('index.php', 'team', uniPrefix: true)]
class TeamController extends AbstractLoginController
{
    public function initialize(): void
    {
        parent::initialize();
    }

    public function show(): Response
    {
        $this->assign([
            'members' => $this->db->select('SELECT name, position, description, contact FROM %%TEAM_MEMBERS%% ORDER BY sort ASC, id ASC;'),
        ]);

        return $this->render('login/page.team.default.html.twig');
    }

}
