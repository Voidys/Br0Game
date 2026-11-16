<?php

declare(strict_types=1);

namespace App\Game\BattleEngine;

/**
 * One fleet of a fight (an attacking fleet, a fleet holding at the target, or the defending planet itself as fleet 0):
 * its owner (the user row as loaded, kept whole because the stored report data carries it), the combat techs,
 * the units, the fleet row and, for the defending planet, the owner's fleet state before the fight.
 *
 * The units are the only mutable part: the engines reduce them to the survivors (and, for the defending planet,
 * add the rebuilt defense) while they fight, so the caller finds the result in the same objects. Everything else is
 * fixed at construction. toArray() gives the array layout of the legacy fleet arrays (battle report data, honor points,
 * loot calculation of old callers).
 */
final class BattleFleet
{
    /** Techs of the owner (weapons, shields, armor, bonus factor). */
    public readonly BattleTechs $techs;

    /**
     * @param int|string $id fleet id; 0 is the defending planet (as in the PHP engine and the result of the rust library)
     * @param array<string, mixed> $player user row of the owner: id (0 = NPC), username, military_tech, shield_tech, defence_tech, optional factor
     * @param array<int, int|float> $units unit id => amount (ships 202..215, defense 401..408); mutated by the engines
     * @param array<string, mixed> $fleetDetail fleet row (start_*, end_*, resource_*, ...) or just the start coordinates of the planet
     * @param array<string, mixed>|null $currentTotalFleetState owner's totalUnits/totalCapacity before the fight (defending planet only)
     */
    public function __construct(
        public readonly int|string $id,
        public readonly array $player,
        public array $units,
        public readonly array $fleetDetail,
        public readonly ?array $currentTotalFleetState = null,
    ) {
        $this->techs = BattleTechs::fromPlayer($player);
    }

    /**
     * Builds a fleet from the legacy fleet array.
     *
     * @param int|string $id fleet id (the array key of the old fleet list)
     * @param array<string, mixed> $fleet keys player, fleetDetail, unit and optionally currentTotalFleetState
     */
    public static function fromArray(int|string $id, array $fleet): self
    {
        return new self(
            $id,
            $fleet['player'],
            $fleet['unit'],
            $fleet['fleetDetail'] ?? [],
            $fleet['currentTotalFleetState'] ?? null,
        );
    }

    /**
     * Fleets keyed by id from a list of legacy fleet arrays (key order kept: it is the firing order).
     *
     * @param array<int|string, array<string, mixed>> $fleets
     * @return array<int|string, self>
     */
    public static function listFromArrays(array $fleets): array
    {
        $list = [];
        foreach ($fleets as $id => $fleet) {
            $list[$id] = self::fromArray($id, $fleet);
        }

        return $list;
    }

    /** User id of the owner (0 for NPC enemies and pirate bases). */
    public function userId(): int|string
    {
        return $this->player['id'];
    }

    /** Name of the owner as shown in the report. */
    public function username(): string
    {
        return (string) $this->player['username'];
    }

    /** Whether the fleet is the planet itself (fleet 0) with its ships and defense. */
    public function isPlanet(): bool
    {
        return $this->id === 0;
    }

    /** Number of ships and defense units of the fleet. */
    public function unitCount(): int|float
    {
        return array_sum($this->units);
    }

    /** Copy with its own unit list (the engines work on copies when they may have to start over). */
    public function copy(): self
    {
        return new self($this->id, $this->player, $this->units, $this->fleetDetail, $this->currentTotalFleetState);
    }

    /**
     * Legacy array layout: player, fleetDetail, unit and, when set, currentTotalFleetState.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $array = [
            'player' => $this->player,
            'fleetDetail' => $this->fleetDetail,
            'unit' => $this->units,
        ];
        if ($this->currentTotalFleetState !== null) {
            $array['currentTotalFleetState'] = $this->currentTotalFleetState;
        }

        return $array;
    }

    /**
     * toArray() of every fleet of a list, keys kept.
     *
     * @param array<int|string, self> $fleets
     * @return array<int|string, array<string, mixed>>
     */
    public static function listToArrays(array $fleets): array
    {
        return array_map(static fn(self $fleet): array => $fleet->toArray(), $fleets);
    }
}
