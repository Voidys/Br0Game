<?php
namespace App\Game\BattleEngine;

use App\Game\Core\Singleton;

/**
 * Base of the PHP battle engines: wires the services, provides the aggregated combat values and the memory
 * guard (raise memory_limit for large fights, abort at 90% usage) used by BattleHandler's engine fallback chain.
 */
abstract class AbstractBattleEngine implements BattleEngineInterface
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}


    /**
     * Largest memory_limit (bytes) a fight may raise the limit to while it runs. Individually simulated ships
     * (PHP engine) cost roughly 400 bytes each; a fight must never be cancelled just because one side is large,
     * so the limit is raised for the duration of the fight and restored afterwards.
     */
    protected const MAX_MEMORY_LIMIT = 2 * 1024 * 1024 * 1024;

    /** Measured ~400 bytes per simulated ship, rounded up for the per-round bookkeeping. */
    protected const BYTES_PER_SHIP = 700;

    /** Time ceiling: the per-ship PHP fight needs seconds per 100k ships, so never simulate more than this one by one. */
    protected const MAX_SIMULATED_SHIPS = 500000;

    private ?string $originalMemoryLimit = null;

    abstract public function getName(): string;

    abstract public function isAvailable(bool $sim = false): bool;

    /**
     * @deprecated use fight() with BattleFleet objects
     * @see BattleEngineInterface::calculateAttack()
     */
    public function calculateAttack(array &$attackers, array &$defenders, int $universe, bool $sim = false, int $simTries = 1): array|false
    {
        $attackFleets = BattleFleet::listFromArrays($attackers);
        $defendFleets = BattleFleet::listFromArrays($defenders);
        $result = $this->fight($attackFleets, $defendFleets, $universe, $sim, $simTries);
        foreach ($attackFleets as $id => $fleet) {
            $attackers[$id]['unit'] = $fleet->units;
        }
        foreach ($defendFleets as $id => $fleet) {
            $defenders[$id]['unit'] = $fleet->units;
        }

        return $result === false ? false : $result->toArray();
    }

    /**
     * Aggregated (not per-ship) combat values per fleet and unit id: 'def' (armor = (metal+crystal)/10 times
     * armor tech), 'shield' and 'att', each multiplied by the unit amount and the owner's tech level (+10% per level).
     * Also stores the tech factors in $fleets[$id]['techs'] as [attack, shield, armor].
     */
    protected function initCombatValues(&$fleets): array
    {
        // INIT COMBAT VALUES
        $CombatCaps = $this->state->CombatCaps;
        $pricelist = $this->state->pricelist;
        $attArray = [];
        foreach ($fleets as $fleetID => $attacker) {

            // init techs
            $attTech = (1 + (0.1 * $attacker['player']['military_tech']));
            $shieldTech = (1 + (0.1 * $attacker['player']['shield_tech']));
            $armorTech = (1 + (0.1 * $attacker['player']['defence_tech']));

            $fleets[$fleetID]['techs'] = [$attTech, $shieldTech, $armorTech];

            foreach ($attacker['unit'] as $element => $amount) {
                $attArray[$fleetID][$element]['def'] = $amount * ($pricelist[$element]['cost'][901] + $pricelist[$element]['cost'][902]) / 10 * $armorTech;
                $attArray[$fleetID][$element]['shield'] = $amount * ($CombatCaps[$element]['shield']) * $shieldTech;
                $attArray[$fleetID][$element]['att'] = $amount * ($CombatCaps[$element]['attack']) * $attTech;
            }
        }

        return $attArray;
    }

    /**
     * How many ships can be simulated one by one right now. Raises memory_limit (up to MAX_MEMORY_LIMIT) when the
     * given number of ships would not fit; call restoreMemoryLimit() when the fight is over.
     */
    protected function shipBudget(int $ships): int
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $reserve = 64 * 1024 * 1024;
        $needed = memory_get_usage(true) + $ships * self::BYTES_PER_SHIP + $reserve;

        if ($limit > 0 && $limit < $needed && $limit < self::MAX_MEMORY_LIMIT) {
            $target = min(self::MAX_MEMORY_LIMIT, $needed);
            if (ini_set('memory_limit', (string) $target) !== false) {
                $this->originalMemoryLimit ??= (string) $limit;
                $limit = $target;
            }
        }

        // unlimited (-1): still plan with the sane maximum, the machine is not infinite either
        $available = ($limit > 0 ? $limit : self::MAX_MEMORY_LIMIT) - memory_get_usage(true) - $reserve;

        return max(1000, min(self::MAX_SIMULATED_SHIPS, intdiv(max(0, $available), self::BYTES_PER_SHIP)));
    }

    /**
     * Puts memory_limit back to the value it had before shipBudget() raised it.
     */
    protected function restoreMemoryLimit(): void
    {
        if ($this->originalMemoryLimit !== null) {
            ini_set('memory_limit', $this->originalMemoryLimit);
            $this->originalMemoryLimit = null;
        }
    }

    /**
     * Guard against running out of memory mid-fight.
     * @return bool false once the process uses more than 90% of memory_limit (unlimited limits always pass).
     */
    protected function checkMemoryUsage() : bool
    {
        try {
            // ini_parse_quantity understands K/M/G suffixes in any case and plain byte values
            $memory_limit = ini_parse_quantity((string) ini_get('memory_limit'));
            if ($memory_limit <= 0) {
                return true; // -1 / 0: unlimited
            }

            $memory = memory_get_usage(true);
            $percent = ($memory / $memory_limit) * 100;
            $percent = round($percent, 1);

            if ($percent > 90) {
                return false; // Memory limit exceeded
            }
        } catch (\Exception $e) {
            error_log('Error: ' . $e->getMessage());
            return false; // Handle exception
        }
        return true; // Memory usage is within limits
    }
}