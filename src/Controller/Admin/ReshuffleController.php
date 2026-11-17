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

namespace App\Controller\Admin;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reshuffles the positions of the planets in the galaxy.
 */
class ReshuffleController extends AbstractAdminController
{
    /** Renders the universe picker. */
    #[GameRoute('admin', 'admin/reshuffle', 'reshuffle')]
    public function show(): Response
    {
        $universeSelect = [];
        foreach ($this->universe()->availableUniverses() as $uniId) {
            $config = $this->configs->get($uniId);
            $universeSelect[$uniId] = sprintf('%s (ID: %d)', $config->uni_name, $uniId);
        }
        ksort($universeSelect);

        return $this->render('admin/ReshufflePage.html.twig', [
            'universes'    => $universeSelect,
        ]);
    }

    /**
     * Reshuffles the positions of all planets of the posted `universe` (PlanetReshuffle::reshuffleAllPlanets) and answers with the
     * result text (shown in the dialog by the page script).
     */
    #[GameRoute('admin', 'admin/reshuffle', 'reshuffle', 'send', ['POST'])]
    public function send(): Response
    {
        $selectedUniverse = $this->input()->int('universe');
        $result = $this->planetReshuffle()->reshuffleAllPlanets($selectedUniverse);

        return new Response($result === true ? $this->state->LNG['rp_done'] : $result);
    }
}
