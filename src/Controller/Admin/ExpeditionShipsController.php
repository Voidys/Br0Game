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
use App\Game\Core\Universe;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Edits the ships that expeditions can find in a universe. */
class ExpeditionShipsController extends AbstractAdminController
{
    /**
     * Selection of ships that an expedition can find (request: uniID, default emulated).
     */
    #[GameRoute('admin', 'admin/expedition/ships', 'expeditionShips')]
    public function show(): Response
    {
        return $this->renderShips();
    }

    /**
     * POST findable_ships[]: saves the selection through ExpeditionShips::saveConfigForUniverse and shows the page with the result message.
     */
    #[GameRoute('admin', 'admin/expedition/ships', 'expeditionShips', 'update', ['POST'])]
    public function update(): Response
    {
        $LNG = $this->state->LNG;
        try {
            $universe = $this->input()->int('uniID', (int) $this->universe()->getEmulated());
            $this->expeditionShips()->saveConfigForUniverse($universe, $this->submittedShips());

            return $this->renderShips($LNG['adm_exp_ships_saved'], 'success');
        } catch (Exception $e) {
            return $this->renderShips($e->getMessage(), 'error');
        }
    }

    /** the page of the universe `uniID` (default: the emulated one), with an optional result message */
    private function renderShips(string $message = '', string $messageType = ''): Response
    {
        $LNG = $this->state->LNG;
        $universe = $this->input()->int('uniID', (int) $this->universe()->getEmulated());

        $shipConfig = $this->expeditionShips()->getConfigForUniverse($universe);

        foreach ($shipConfig as $shipID => &$config) {
            $config['label'] = $LNG['tech'][$shipID] ?? $shipID;

            $finderIds = array_fill_keys($config['findable_by'], true);
            $config['finders'] = [];
            foreach ($this->elements()->ids('fleet') as $finderShip) {
                $config['finders'][] = [
                    'id' => $finderShip,
                    'label' => $LNG['tech'][$finderShip] ?? $finderShip,
                    'selected' => isset($finderIds[$finderShip]),
                ];
            }
        }

        return $this->render('admin/ExpeditionShips.html.twig', [
            'universe' => $universe,
            'findableShips' => $shipConfig,
            'message' => $message,
            'messageType' => $messageType,
        ]);
    }

    /**
     * The posted `findable_ships[<ship id>][is_findable|findable_by[]]` as the handler expects it (flag and finder ids).
     *
     * @return array<int, array{is_findable: int, findable_by: list<int>}>
     */
    private function submittedShips(): array
    {
        $submitted = [];
        $input = $this->state->requestInput['findable_ships'] ?? [];
        foreach (is_array($input) ? $input : [] as $shipId => $config) {
            if (!is_array($config)) {
                continue;
            }
            $finders = is_array($config['findable_by'] ?? null) ? array_filter($config['findable_by'], 'is_scalar') : [];
            $submitted[(int) $shipId] = [
                'is_findable' => (int) ($config['is_findable'] ?? 0),
                'findable_by' => array_values(array_map('intval', $finders)),
            ];
        }

        return $submitted;
    }
}
