<?php

declare(strict_types=1);

namespace App\Game\Model;

/**
 * Typed, immutable read model of one player row (`users`) as the game mechanics see it.
 *
 * What it is: a snapshot of the `$USER` state array (identity, home coordinates, protection/vacation/ban data,
 * queue state, fleet-relevant bonus factors) plus the player's technology levels as a typed element map. It is built
 * from the array at the moment of the call; it does NOT follow later changes of the array.
 *
 * What it is not: a write model. Mutations (research started, vacation toggled, ...) still go through the state
 * array and its persistence code; build a fresh snapshot afterwards when the new values matter.
 *
 * How to get one: `$this->models()->user($USER)` in any class using the `GameUtilities` trait, or
 * `User::fromState($row, $resource)` where `$resource` is the element id => column name table of the game data
 * (`$this->state->resource`). Missing or NULL keys never raise: they read as 0 / '' / false, tech levels not present
 * in the row are reported by `has()` as false and read as 0.
 */
final readonly class User
{
    /**
     * @param array<int, int>   $levels  technology level per element id (only ids whose column is present in the row)
     * @param array<string, float> $factors bonus factors by name (e.g. 'FlyTime'), the `factor` key of the state array
     */
    public function __construct(
        public int $id,
        public string $username,
        public int $universe,
        public int $authLevel,
        public int $authAttack,
        public int $homePlanetId,
        public int $galaxy,
        public int $system,
        public int $position,
        public int $registerTime,
        public int $onlineTime,
        public int $allianceId,
        public int $allianceRankId,
        public bool $vacation,
        public int $vacationUntil,
        public int $vacationStart,
        public int $banUntil,
        public int $rebuildProtection,
        public string $rebuildProtectionAttackers,
        public int $rebuildProtectionLockedUntil,
        public int $outlawTime,
        public int $wreckfieldCooldownUntil,
        public int $totalPoints,
        public int $researchEnd,
        public int $researchPlanetId,
        public int $researchElementId,
        public string $researchQueue,
        private array $levels,
        public array $factors,
    ) {}

    /**
     * Builds the snapshot from a player row / `$USER` state array.
     *
     * @param array<string, mixed> $row      the player row (extra keys such as `factor` or `total_points` are used when present)
     * @param array<int, string>   $resource element id => column name table (`$this->state->resource`)
     */
    public static function fromState(array $row, array $resource): self
    {
        $levels = [];
        foreach ($resource as $elementId => $column) {
            if (isset($row[$column]) && is_numeric($row[$column])) {
                $levels[(int) $elementId] = (int) $row[$column];
            }
        }

        $factors = [];
        if (isset($row['factor']) && is_array($row['factor'])) {
            foreach ($row['factor'] as $name => $value) {
                $factors[(string) $name] = (float) $value;
            }
        }

        return new self(
            id: (int) ($row['id'] ?? 0),
            username: (string) ($row['username'] ?? ''),
            universe: (int) ($row['universe'] ?? 0),
            authLevel: (int) ($row['authlevel'] ?? 0),
            authAttack: (int) ($row['authattack'] ?? 0),
            homePlanetId: (int) ($row['id_planet'] ?? 0),
            galaxy: (int) ($row['galaxy'] ?? 0),
            system: (int) ($row['system'] ?? 0),
            position: (int) ($row['planet'] ?? 0),
            registerTime: (int) ($row['register_time'] ?? 0),
            onlineTime: (int) ($row['onlinetime'] ?? 0),
            allianceId: (int) ($row['ally_id'] ?? 0),
            allianceRankId: (int) ($row['ally_rank_id'] ?? 0),
            vacation: (int) ($row['urlaubs_modus'] ?? 0) === 1,
            vacationUntil: (int) ($row['urlaubs_until'] ?? 0),
            vacationStart: (int) ($row['urlaubs_start'] ?? 0),
            banUntil: (int) ($row['banaday'] ?? 0),
            rebuildProtection: (int) ($row['rebuild_protection'] ?? 0),
            rebuildProtectionAttackers: (string) ($row['rebuild_protection_attackers'] ?? ''),
            rebuildProtectionLockedUntil: (int) ($row['rebuild_protection_locked_until'] ?? 0),
            outlawTime: (int) ($row['outlaw_time'] ?? 0),
            wreckfieldCooldownUntil: (int) ($row['wreckfield_cooldown_until'] ?? 0),
            totalPoints: (int) ($row['total_points'] ?? 0),
            researchEnd: (int) ($row['b_tech'] ?? 0),
            researchPlanetId: (int) ($row['b_tech_planet'] ?? 0),
            researchElementId: (int) ($row['b_tech_id'] ?? 0),
            researchQueue: (string) ($row['b_tech_queue'] ?? ''),
            levels: $levels,
            factors: $factors,
        );
    }

    /** Whether the row carried a value for this element (the `isset($USER[$resource[$id]])` test of the array code). */
    public function has(int $elementId): bool
    {
        return isset($this->levels[$elementId]);
    }

    /** Technology level of an element; 0 when the row has no such column. */
    public function level(int $elementId): int
    {
        return $this->levels[$elementId] ?? 0;
    }

    /** Bonus factor by name (e.g. 'FlyTime'); null when the player carries no such factor. */
    public function factor(string $name): ?float
    {
        return $this->factors[$name] ?? null;
    }

    /** Banned at the given time (`banaday` lies in the future). */
    public function isBanned(int $now): bool
    {
        return $this->banUntil > $now;
    }

    /** Whether the player has not been online for longer than $threshold seconds. */
    public function isInactive(int $now, int $threshold): bool
    {
        return $this->onlineTime < $now - $threshold;
    }
}
