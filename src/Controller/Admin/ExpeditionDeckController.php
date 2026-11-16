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
use App\Game\Core\Database;
use App\Game\Core\ExpeditionDeckHandler;
use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Game\Core\Universe;
use Symfony\Component\HttpFoundation\Response;

/** Edits the expedition event deck of a universe. */
#[\App\Routing\GamePage('admin.php', 'expeditionDeck')]
#[\App\Routing\GamePage('admin.php', 'expeditionDeck', uniPrefix: true)]
class ExpeditionDeckController extends AbstractAdminController
{
    public function show(): Response
    {
        $LNG = $this->state->LNG;

        $universe = $this->http()->_GP('uniID', $this->universe()->getEmulated());
        $action = $this->http()->_GP('action', 'show');

        $message = '';
        $messageType = '';

        if ($action === 'update' && !empty($this->state->requestPost)) {
            try {
                $this->updateDeckConfig($universe);
                $message = $LNG['exp_deck_save_success'];
                $messageType = 'success';
            } catch (Exception $e) {
                $message = $e->getMessage();
                $messageType = 'error';
            }
        }

        $deckConfig = $this->getDeckConfig($universe);
        $deckStats = $this->calculateDeckStats($deckConfig);

        $this->assign([
            'universe' => $universe,
            'deckConfig' => $deckConfig,
            'deckStats' => $deckStats,
            'deckJson' => json_encode($deckConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'message' => $message,
            'messageType' => $messageType,
        ]);

        return $this->render('admin/ExpeditionDeck.html.twig');
    }

    private function getDeckConfig(int $universe): array
    {
        $db = $this->db;

        $sql = 'SELECT `deck_data` FROM %%EXPEDITION_DECK_CONFIG%% WHERE `universe` = :universe;';
        $result = $db->selectSingle($sql, [':universe' => $universe]);

        if ($result) {
            return json_decode($result['deck_data'], true);
        }

        // Return default deck configuration
        return ExpeditionDeckHandler::$defaultDeckConfig;
    }

    private function calculateDeckStats(array $deckConfig): array
    {
        $stats = [
            'total_cards' => 0,
            'by_type' => [],
        ];

        foreach ($deckConfig as $card) {
            $stats['total_cards'] += $card['count'];
            $type = $card['type'];
            if (!isset($stats['by_type'][$type])) {
                $stats['by_type'][$type] = 0;
            }
            $stats['by_type'][$type] += $card['count'];
        }

        // Calculate percentages
        foreach ($stats['by_type'] as &$count) {
            $count = [
                'cards' => $count,
                'percentage' => round($count / $stats['total_cards'] * 100, 1),
            ];
        }

        return $stats;
    }

    private function updateDeckConfig(int $universe): void
    {
        $jsonInput = $this->state->requestInput['deck_config_json'] ?? '';

        try {
            $deckConfig = json_decode($jsonInput, true, 512, JSON_THROW_ON_ERROR);

            // Validate deck configuration
            if (!is_array($deckConfig)) {
                throw new Exception($this->state->LNG['adm_exp_deck_err_format']);
            }

            $totalCards = 0;
            foreach ($deckConfig as $card) {
                if (!isset($card['type'], $card['size'], $card['count'])) {
                    throw new Exception($this->state->LNG['adm_exp_deck_err_card']);
                }
                if ($card['count'] < 0) {
                    throw new Exception($this->state->LNG['adm_exp_deck_err_count']);
                }
                $totalCards += $card['count'];
            }

            if ($totalCards === 0) {
                throw new Exception($this->state->LNG['adm_exp_deck_err_empty']);
            }

            (new ExpeditionDeckHandler($this->db, $this->configs, $this->state))->updateDeckConfig($universe, $deckConfig);

        } catch (\JsonException $e) {
            // JSON parsing error
            throw new Exception($this->state->LNG['adm_exp_deck_err_json'] . ': ' . $e->getMessage());
        } catch (Exception $e) {
            throw $e;
        }
    }
}
