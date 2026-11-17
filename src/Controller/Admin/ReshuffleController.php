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

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\HTTP;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;

/**
 * Reshuffles the positions of the planets in the galaxy.
 */
#[\App\Routing\GamePage('admin', 'reshuffle')]
class ReshuffleController extends AbstractAdminController
{
    /**
     * action=send with universe: reshuffles the positions of all planets of that universe (PlayerUtil::reshuffleAllPlanets) and ends with
     * the result text. Otherwise renders the universe picker.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $ACTION = $this->http()->_GP('action', '');
        if ($ACTION == 'send') {
            $selectedUniverse = $this->http()->_GP('universe', '');
            $result = $this->playerUtil()->reshuffleAllPlanets($selectedUniverse);
            if ($result === true) {
                throw new \App\Game\Core\EndOfRequest($LNG['rp_done']);
            }
            throw new \App\Game\Core\EndOfRequest($result);
        }
        $universeSelect = [];
        foreach ($this->universe()->availableUniverses() as $uniId) {
            $config = $this->configs->get($uniId);
            $universeSelect[$uniId] = sprintf('%s (ID: %d)', $config->uni_name, $uniId);
        }
        ksort($universeSelect);

        $this->assign([
            'universes'    => $universeSelect,
        ]);
        return $this->render('admin/ReshufflePage.html.twig');
    }
}
