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

namespace App\Game\Core;

use App\Game\Core\Database;

class ExpeditionDeckHandler
{
    private int $userId;
    private int $universe;
    private array $deckArray = [];
    public static array $defaultDeckConfig = [
        ['type' => 'Res', 'size' => 'Small', 'count' => 329],  // 37%
        ['type' => 'Res', 'size' => 'Medium', 'count' => 37],
        ['type' => 'Res', 'size' => 'Big', 'count' => 4],
        ['type' => 'Ships', 'size' => 'Small', 'count' => 235],  // 26.4%
        ['type' => 'Ships', 'size' => 'Medium', 'count' => 26],
        ['type' => 'Ships', 'size' => 'Big', 'count' => 3],
        ['type' => 'Pirates', 'size' => 'Small', 'count' => 51],  // 5.7%
        ['type' => 'Pirates', 'size' => 'Medium', 'count' => 5],
        ['type' => 'Pirates', 'size' => 'Big', 'count' => 1],
        ['type' => 'Aliens', 'size' => 'Small', 'count' => 23],  // 2.6%
        ['type' => 'Aliens', 'size' => 'Medium', 'count' => 2],
        ['type' => 'Aliens', 'size' => 'Big', 'count' => 1],
        ['type' => 'Fast', 'size' => 'Small', 'count' => 17],  // 2.3%
        ['type' => 'Fast', 'size' => 'Medium', 'count' => 4],
        ['type' => 'Fast', 'size' => 'Big', 'count' => 2],
        ['type' => 'Slow', 'size' => 'Small', 'count' => 48],  // 6.7%
        ['type' => 'Slow', 'size' => 'Medium', 'count' => 13],
        ['type' => 'Slow', 'size' => 'Big', 'count' => 6],
        ['type' => 'BlackHole', 'size' => 'None', 'count' => 3],  // 0.3%
        ['type' => 'Nothing', 'size' => 'None', 'count' => 190],  // 19%
    ];

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
        int $userId, int $universe,
    ) {
        $this->userId = $userId;
        $this->universe = $universe;
        $this->loadUserDeck();
    }

    /**
     * Load or create user deck
     */
    private function loadUserDeck(bool $forUpdate = false): void
    {
        $db = $this->db;

        $sql = 'SELECT * FROM %%EXPEDITION_USER_DECK%% WHERE `user_id` = :user_id AND `universe` = :universe';
        if ($forUpdate) {
            // Relies on the outer FleetHandler transaction; do not start a nested one.
            $sql .= ' FOR UPDATE';
        }
        $sql .= ';';

        $result = $db->selectSingle($sql, [
            ':user_id' => $this->userId,
            ':universe' => $this->universe,
        ]);

        if (!$result) {
            // Create new deck for user
            $this->regenerateDeck();
        } else {
            // Load existing deck
            $this->deckArray = json_decode($result['deck_array'], true) ?? [];

            if (empty($this->deckArray)) {
                $this->regenerateDeck();
            }
        }
    }

    /**
     * Get deck configuration from database or use default
     */
    private function getDeckConfig(): array
    {
        $db = $this->db;

        $sql = 'SELECT `deck_data` FROM %%EXPEDITION_DECK_CONFIG%% WHERE `universe` = :universe;';
        $result = $db->selectSingle($sql, [
            ':universe' => $this->universe,
        ]);

        $config = $result ? json_decode($result['deck_data'], true) : self::$defaultDeckConfig;

        return array_values(array_filter($config, static function ($cardType) {
            return isset($cardType['type'], $cardType['size'], $cardType['count']) && $cardType['count'] > 0;
        }));
    }

    /**
     * Generate deck based on configuration
     */
    private function generateDeckArray(): array
    {
        return $this->getDeckConfig();
    }

    /**
     * Remaining individual cards across all type slots
     */
    private function getTotalCardCount(): int
    {
        $total = 0;
        // for each card type, add the count to the total
        foreach ($this->deckArray as $cardType) {
            $total += (int) ($cardType['count'] ?? 0);
        }

        return $total;
    }

    /**
     * Regenerate deck (reset counts from config)
     */
    public function regenerateDeck(): void
    {
        $this->deckArray = $this->generateDeckArray();
        $this->saveDeck();
    }

    /**
     * Draw next card from deck, weighted by remaining counts.
     * Locks the user deck row (FOR UPDATE) within the outer fleet transaction.
     */
    public function drawCard(): array
    {
        $this->loadUserDeck(true);

        $totalCards = $this->getTotalCardCount();
        if ($totalCards <= 0) {
            $this->regenerateDeck();
            $totalCards = $this->getTotalCardCount();
        }

        if ($totalCards <= 0) {
            // Should never happen, but just in case
            return [
                'type' => 'Nothing',
                'size' => 'None',
            ];
        }

        // $totalCards = sum of all slot counts (what's left in the deck).
        // $roll picks one of those remaining cards (1..$totalCards).
        // $accumulated is a cursor that walks slot ranges (e.g. 1-329, 330-366, ...) until $roll hits one.
        $roll = random_int(1, $totalCards);
        $accumulated = 0;
        $selectedIndex = 0;

        foreach ($this->deckArray as $index => $cardType) {
            $accumulated += (int) $cardType['count'];
            if ($roll <= $accumulated) {
                $selectedIndex = $index;
                break;
            }
        }

        $card = $this->deckArray[$selectedIndex];
        $this->deckArray[$selectedIndex]['count']--;

        if ($this->deckArray[$selectedIndex]['count'] <= 0) {
            array_splice($this->deckArray, $selectedIndex, 1);
        }

        if (empty($this->deckArray)) {
            $this->regenerateDeck();
        } else {
            $this->saveDeck();
        }

        return [
            'type' => $card['type'],
            'size' => $card['size'],
        ];
    }

    /**
     * Save deck state to database
     */
    private function saveDeck(): void
    {
        $db = $this->db;

        $sql = 'INSERT INTO %%EXPEDITION_USER_DECK%%
                (`user_id`, `universe`, `deck_array`, `updated_at`)
                VALUES (:user_id, :universe, :deck_array, NOW())
                ON DUPLICATE KEY UPDATE
                `deck_array` = VALUES(`deck_array`),
                `updated_at` = NOW();';

        $db->insert($sql, [
            ':user_id' => $this->userId,
            ':universe' => $this->universe,
            ':deck_array' => json_encode($this->deckArray)
        ]);
    }

    /**
     * Update deck configuration for universe
     */
    public function updateDeckConfig(int $universe, array $config): void
    {
        $db = $this->db;

        $sql = 'INSERT INTO %%EXPEDITION_DECK_CONFIG%%
                (`universe`, `deck_data`, `updated_at`)
                VALUES (:universe, :deck_data, NOW())
                ON DUPLICATE KEY UPDATE
                `deck_data` = VALUES(`deck_data`),
                `updated_at` = NOW();';

        $db->insert($sql, [
            ':universe' => $universe,
            ':deck_data' => json_encode($config),
        ]);

        // Reset all user decks for this universe
        $sql = 'DELETE FROM %%EXPEDITION_USER_DECK%% WHERE `universe` = :universe;';
        $db->delete($sql, [':universe' => $universe]);
    }
}
