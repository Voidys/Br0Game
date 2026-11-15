<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Admin;

use Exception;
use App\Game\Core\ExpeditionFindableShipsHandler;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;

/** Edits the ships that expeditions can find in a universe. */
#[\App\Routing\GamePage('admin.php', 'expeditionShips')]
#[\App\Routing\GamePage('admin.php', 'expeditionShips', uniPrefix: true)]
class ExpeditionShipsController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $reslist = & $this->state->reslist;

        $universe = $this->http()->_GP('uniID', $this->universe()->getEmulated());
        $action = $this->http()->_GP('action', 'show');

        $message = '';
        $messageType = '';

        if ($action === 'update' && !empty($this->state->requestPost)) {
            try {
                $this->expeditionShips()->saveConfigForUniverse($universe, $this->http()->_GP('findable_ships', []));
                $message = $LNG['adm_exp_ships_saved'];
                $messageType = 'success';
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        }

        $shipConfig = $this->expeditionShips()->getConfigForUniverse($universe);

        foreach ($shipConfig as $shipID => &$config) {
            $config['label'] = $LNG['tech'][$shipID] ?? $shipID;

            $finderIds = array_fill_keys($config['findable_by'], true);
            $config['finders'] = [];
            foreach ($reslist['fleet'] as $finderShip) {
                $config['finders'][] = [
                    'id' => $finderShip,
                    'label' => $LNG['tech'][$finderShip] ?? $finderShip,
                    'selected' => isset($finderIds[$finderShip]),
                ];
            }
        }

        $this->assign([
            'universe' => $universe,
            'findableShips' => $shipConfig,
            'message' => $message,
            'messageType' => $messageType,
        ]);

        return $this->render('admin/ExpeditionShips.html.twig');
    }
}
