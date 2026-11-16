<?php
namespace App\Game\BattleEngine;

/**
 * Contract of a combat engine: simulate a fight between attacking and defending fleets (BattleFleet, built by the
 * missions and the simulator) and return the result. Implementations: Rust (fast, via bin/battlerust) and SteemNova (PHP).
 */
interface BattleEngineInterface
{
    /**
     * Fights. The units of the given fleets are reduced to the survivors in place (the defending planet also gets its
     * rebuilt defense back); the returned result carries the winner, round log, losses, debris, repaired defense and wreck field.
     *
     * @param array<int|string, BattleFleet> $attackers attacking fleets by fleet id, in firing order
     * @param array<int|string, BattleFleet> $defenders defending fleets by fleet id; the planet itself is fleet 0
     * @param int $universe universe whose debris, wreck field and protection settings apply
     * @param bool $sim simulator run: nothing is written to the statistics
     * @return BattleResult|false false when no engine could fight it (too large even for the scaled PHP fight)
     */
    public function fight(
        array $attackers,
        array $defenders,
        int $universe,
        bool $sim = false,
        int $simTries = 1
    ): BattleResult|false;

    /**
     * Array adapter of fight() for callers that still hold legacy fleet arrays: the fleets are wrapped, fought, and the
     * surviving units are written back into the arrays' `unit` entries.
     *
     * @deprecated use fight() with BattleFleet objects
     * @param array<int|string, array<string, mixed>> $attackers legacy fleet arrays (player, fleetDetail, unit); `unit` is updated by reference
     * @param array<int|string, array<string, mixed>> $defenders likewise
     * @return array<string, mixed>|false BattleResult::toArray() or false
     */
    public function calculateAttack(
        array &$attackers,
        array &$defenders,
        int $universe,
        bool $sim = false,
        int $simTries = 1
    ): array|false;

    public function getName(): string;

    public function isAvailable(bool $sim = false): bool;
}
