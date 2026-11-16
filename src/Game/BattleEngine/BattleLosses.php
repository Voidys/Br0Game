<?php

declare(strict_types=1);

namespace App\Game\BattleEngine;

/**
 * What each side lost in a fight: the metal+crystal value per side and, per participating user, the lost value and the lost
 * transport capacity (every participant is listed, also without losses). Feeds the rebuild protection, the honor points,
 * the statistics and the report messages. Immutable.
 */
final class BattleLosses
{
    /**
     * @param int|float $attackerValue metal+crystal value lost by the attacking side
     * @param int|float $defenderValue metal+crystal value lost by the defending side
     * @param array{attacker: array<int|string, int|float>, defender: array<int|string, int|float>} $valuePerUser lost value by side and user id
     * @param array{attacker: array<int|string, int|float>, defender: array<int|string, int|float>} $capacityPerUser lost transport capacity by side and user id
     */
    public function __construct(
        public readonly int|float $attackerValue,
        public readonly int|float $defenderValue,
        public readonly array $valuePerUser,
        public readonly array $capacityPerUser,
    ) {}

    /** Metal+crystal value lost by a side. */
    public function value(BattleSide $side): int|float
    {
        return $side === BattleSide::Attacker ? $this->attackerValue : $this->defenderValue;
    }

    /** Both sides' lost value together (the "units" of the top-KB list and the report messages). */
    public function totalValue(): int|float
    {
        return $this->attackerValue + $this->defenderValue;
    }

    /** Lost value of one user on a side; 0 when the user did not take part on that side. */
    public function userValue(BattleSide $side, int|string $userId): int|float
    {
        return $this->valuePerUser[$side->value][$userId] ?? 0;
    }

    /** Lost transport capacity of one user on a side; 0 when the user did not take part on that side. */
    public function userCapacity(BattleSide $side, int|string $userId): int|float
    {
        return $this->capacityPerUser[$side->value][$userId] ?? 0;
    }

    /**
     * Ids of the users that took part on a side.
     *
     * @return list<int|string>
     */
    public function userIds(BattleSide $side): array
    {
        return array_keys($this->valuePerUser[$side->value]);
    }
}
