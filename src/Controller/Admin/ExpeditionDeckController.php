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
use App\Game\Core\ExpeditionDeckHandler;
use App\Game\Core\Universe;
use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;

/** Edits the expedition event deck of a universe. */
class ExpeditionDeckController extends AbstractAdminController
{
    /**
     * Expedition event deck of a universe (request: uniID, default emulated).
     * Shows the deck cards with computed statistics (card counts and percentages per type).
     */
    #[GameRoute('admin', 'admin/expedition/deck', 'expeditionDeck')]
    public function show(): Response
    {
        return $this->renderDeck();
    }

    /** POST: saves the submitted deck (JSON) of the universe `uniID` and shows the deck with the result message. */
    #[GameRoute('admin', 'admin/expedition/deck', 'expeditionDeck', 'update', ['POST'])]
    public function update(): Response
    {
        $LNG = $this->state->LNG;
        try {
            $this->updateDeckConfig($this->input()->int('uniID', (int) $this->universe()->getEmulated()));

            return $this->renderDeck($LNG['exp_deck_save_success'], 'success');
        } catch (Exception $e) {
            return $this->renderDeck($e->getMessage(), 'error');
        }
    }

    /** the deck page of the universe `uniID` (default: the emulated one), with an optional result message */
    private function renderDeck(string $message = '', string $messageType = ''): Response
    {
        $universe = $this->input()->int('uniID', (int) $this->universe()->getEmulated());

        $deckConfig = $this->getDeckConfig($universe);
        $deckStats = $this->calculateDeckStats($deckConfig);

        return $this->render('admin/ExpeditionDeck.html.twig', [
            'universe' => $universe,
            'deckStats' => $deckStats,
            'deckJson' => json_encode($deckConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            'message' => $message,
            'messageType' => $messageType,
        ]);
    }

    /**
     * @return array<mixed>
     */
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

    /**
     * Counts the cards per event type and their share of the deck; returns the totals used by the page.
     *
     * @param array<mixed> $deckConfig
     * @return array<mixed>
     */
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

    /**
     * Validates the posted deck JSON (every card needs type, size and count, count >= 0, at least one card) and stores it for the
     * universe; invalid JSON or an empty deck is rejected with a message.
     */
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

            (new ExpeditionDeckHandler($this->db, $this->configs, $this->state, (int) $this->state->USER['id'], $universe))->updateDeckConfig($universe, $deckConfig);

        } catch (\JsonException $e) {
            // JSON parsing error
            throw new Exception($this->state->LNG['adm_exp_deck_err_json'] . ': ' . $e->getMessage());
        } catch (Exception $e) {
            throw $e;
        }
    }
}
