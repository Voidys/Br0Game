<?php

namespace App\Game\Bots\Raid;

/**
 * What a raiding bot remembers, kept in its personality JSON (`_raid`, `farm`): targets it looked at (when it spied, how many
 * probes it needed, when to leave them alone), probes under way, attacks under way with the plan and the pre-impact re-spy
 * schedule, the raid streak, and the farm list of targets that gave loot. Plain arrays; this class only knows their shape.
 *
 * `_raid`: `targets` (planet id => `user`, `spy_at`, `spy_try`, `tspy`, `skip_until`, `last_hit`), `spies` (fleet id => `planet`,
 * `sent`, `arrive`, `target`, `n`), `attacks` (fleet id => `planet`, `user`, `name`, `coords`, `impact`, `origin`, `plan`, `state`
 * none / scheduled / probing / done, `recheck_at`, `probe_sent`, `read_at`, `settle_at`, `n`), `streak` (`start`, `until`, `hits`,
 * `idle`), `cand` (cached scouting result), `scouted_at`.
 */
final class RaidMemory
{
    /** Farm list size. */
    private const FARM_MAX = 20;

    /**
     * The raid memory of a personality (empty structure when none yet).
     *
     * @param array<string, mixed> $personality
     * @return array<string, mixed>
     */
    public static function of(array $personality): array
    {
        $mem = is_array($personality['_raid'] ?? null) ? $personality['_raid'] : [];

        return $mem + ['targets' => [], 'spies' => [], 'attacks' => [], 'cand' => [], 'scouted_at' => 0];
    }

    /**
     * Drops what is stale: targets not seen for two days, probes older than an hour, finished attacks.
     *
     * @param array<string, mixed> $mem raid memory
     */
    public static function prune(array &$mem, int $now): void
    {
        foreach ($mem['targets'] as $id => $t) {
            if (($t['seen'] ?? 0) < $now - 172800) {
                unset($mem['targets'][$id]);
            }
        }
        foreach ($mem['spies'] as $id => $s) {
            if (($s['sent'] ?? 0) < $now - 3600) {
                unset($mem['spies'][$id]);
            }
        }
        foreach ($mem['attacks'] as $id => $a) {
            if (($a['state'] ?? '') === 'settled' || ($a['impact'] ?? 0) < $now - 86400) {
                unset($mem['attacks'][$id]);
            }
        }
    }

    /**
     * The earliest moment a raid task needs the bot: a re-spy to send, a report to read, a hit to settle.
     *
     * @param array<string, mixed> $personality
     */
    public static function nextWake(array $personality): ?int
    {
        $mem = self::of($personality);
        $times = [];
        foreach ($mem['attacks'] as $a) {
            $state = $a['state'] ?? 'none';
            if ($state === 'scheduled') {
                $times[] = (int) $a['recheck_at'];
            } elseif ($state === 'probing') {
                $times[] = (int) $a['read_at'];
            }
            if ($state !== 'settled' && !empty($a['settle_at'])) {
                $times[] = (int) $a['settle_at'];
            }
        }

        return $times === [] ? null : min($times);
    }

    /**
     * Whether a raid streak is running.
     *
     * @param array<string, mixed> $personality
     */
    public static function streakActive(array $personality, int $now): bool
    {
        $streak = self::of($personality)['streak'] ?? null;

        return is_array($streak) && (int) $streak['until'] > $now;
    }

    /**
     * Adds a target to the farm list (newest first, capped), replacing an older entry of the same planet.
     *
     * @param array<string, mixed> $personality changed in place
     */
    public static function farm(array &$personality, int $planetId, int $userId, string $name, float $loot, int $now): void
    {
        $list = array_values(array_filter((array) ($personality['farm'] ?? []), static fn(array $e): bool => (int) $e['p'] !== $planetId));
        array_unshift($list, ['p' => $planetId, 'u' => $userId, 'name' => $name, 'loot' => round($loot), 'at' => $now]);
        $personality['farm'] = array_slice($list, 0, self::FARM_MAX);
    }

    /**
     * The targets memory with the farm list laid over it (planets that gave loot before get `last_loot`).
     *
     * @param array<int, array<string, mixed>> $targets targets memory by planet id
     * @param array<string, mixed> $personality
     * @return array<int, array<string, mixed>> by planet id
     */
    public static function withFarm(array $targets, array $personality): array
    {
        $known = $targets;
        foreach ((array) ($personality['farm'] ?? []) as $entry) {
            $known[(int) $entry['p']]['last_loot'] = (float) $entry['loot'];
        }

        return $known;
    }
}
