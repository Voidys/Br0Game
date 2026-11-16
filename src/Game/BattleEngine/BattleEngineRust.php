<?php

namespace App\Game\BattleEngine;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;
use App\Game\Core\Singleton;

/**
 * Rust battle engine. bin/battlerust (a small launcher built from tools/battlerust-cli/) loads
 * bin/libbattlerust.so (source: tools/battlerust/) and exchanges the battle as JSON over stdin/stdout:
 * createRustJson() builds the input, parseRustJson() applies the result. No PHP extension needed; one process start
 * (about 2 ms) per battle. Linux x86_64 only; elsewhere BattleHandler falls back to the PHP engine.
 *
 * The library fights like BattleEngineSteemNova ship by ship with PHP's mt_rand; with useSeed() both engines produce
 * the same fight (docs/battle-engine-contract.md), which the parity tests rely on. The rounds of the library are
 * only the fight; losses, debris, defense rebuild and wreck field are computed here, in the order and with the
 * random draws of the PHP engine.
 */
class BattleEngineRust extends AbstractBattleEngine
{
    /** Seed handed to the library (tests and diagnostics only); null = the library seeds itself from the OS. */
    private ?int $seed = null;

    /**
     * Makes the next fights reproducible: the library is seeded with $seed and, afterwards, PHP's generator is
     * moved to the point where the PHP engine would stand after the same fight (mt_srand($seed) plus the draws the
     * library consumed), so the defense rebuild rolls match the PHP engine seeded with mt_srand($seed).
     * Pass null to go back to random fights. Not used by the game itself.
     */
    public function useSeed(?int $seed): void
    {
        $this->seed = $seed;
    }
    /** True once a fight fell back to the PHP engine (the library refused or failed), see calculateAttack(). */
    private bool $fellBack = false;

    /**
     * Stored with every report: 'rust' reports are compressed and use the rust report template. After a fight that fell
     * back to the PHP engine the name is that engine's, because the round log of the report has its format.
     */
    public function getName(): string
    {
        return $this->fellBack ? 'steemnova' : 'rust';
    }

    /**
     * @return bool true on Linux with proc_open, an executable bin/battlerust and bin/libbattlerust.so.
     */
    public function isAvailable(bool $sim = false): bool
    {
        return \function_exists('proc_open')
            && \PHP_OS_FAMILY === 'Linux'
            && \is_executable(self::binary())
            && \is_file(\App\Runtime\ProjectPaths::ROOT . 'bin/libbattlerust.so');
    }

    /**
     * Serialises the fleets to JSON, runs the Rust library and converts its round log back into a BattleResult (same
     * content as the PHP engine's). The surviving units are written back into the given fleets.
     * When the library refuses the fight (more ships than its memory guard, 60 million) or the process fails, the
     * fight is run by the PHP engine instead (which scales huge fleets down), so a battle is never cancelled; the engine
     * name (getName()) then reports 'steemnova'. The failure is logged.
     * @return BattleResult|false false only when the PHP engine fails as well.
     */
    public function fight(array $attackers, array $defenders, int $universe, bool $sim = false, int $simTries = 1): BattleResult|false
    {
        $executionTime = microtime(true);

        $json = $this->engineJson($attackers, $defenders);
        $output = $this->runEngine($json);
        if ($output === null) {
            \error_log('battlerust: the Rust engine failed or refused the fight, using the PHP engine');
            $this->fellBack = true;
            $result = (new BattleEngineSteemNova($this->db, $this->configs, $this->state))->fight($attackers, $defenders, $universe, $sim, $simTries);
            $this->fellBack = $result !== false;

            return $result;
        }
        $this->fellBack = false;

        return $this->parseRustJson($output, $attackers, $defenders, $universe, $sim, $executionTime);
    }

    /** battle input JSON → result JSON (null: the engine failed) */
    protected function runEngine(string $json): ?string
    {
        // stderr (the library's progress lines) goes to /dev/null, so a full pipe can never block the engine
        $process = \proc_open([self::binary()], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        if (!\is_resource($process)) {
            return null;
        }

        \fwrite($pipes[0], $json);
        \fclose($pipes[0]);
        $output = \stream_get_contents($pipes[1]);
        \fclose($pipes[1]);
        $exitCode = \proc_close($process);

        return $exitCode === 0 && \is_string($output) && $output !== '' ? $output : null;
    }

    private static function binary(): string
    {
        return \App\Runtime\ProjectPaths::ROOT . 'bin/battlerust';
    }

    /**
     * Builds the engine input: six rounds, per side a list of fleets with one entry per unit type (attack with
     * +10%/level weapon tech, hull = (metal+crystal)/10 with armor tech, shield with shield tech, rapid fire table,
     * bigshield/stun flags, explode threshold 0.7 for ships (ids 2xx), none for defenses), and the seed when useSeed() was
     * called. The values are NOT rounded: they are the exact numbers of the PHP engine, which seeded parity depends on.
     * The key order is part of the format (docs/battle-engine-contract.md) and pinned by a test.
     *
     * @param array<int|string, BattleFleet> $attackers attacking fleets in firing order
     * @param array<int|string, BattleFleet> $defenders defending fleets in firing order (the planet is fleet 0)
     * @return array<string, mixed> the document json_encode()d by engineJson()
     */
    public function engineInput(array $attackers, array $defenders): array
    {
        $data = [
            'rounds'   => 6,
            'attacker' => $this->engineFleets($attackers),
            'defender' => $this->engineFleets($defenders),
        ];

        if ($this->seed !== null) {
            $data['seed'] = $this->seed;
        }

        return $data;
    }

    /**
     * The engine input of engineInput() as the JSON string sent to bin/battlerust (default json_encode flags: floats keep
     * their shortest round-trip form, whole floats are written without a fraction).
     *
     * @param array<int|string, BattleFleet> $attackers
     * @param array<int|string, BattleFleet> $defenders
     */
    public function engineJson(array $attackers, array $defenders): string
    {
        return json_encode($this->engineInput($attackers, $defenders));
    }

    /**
     * One side of the engine input: a list of {name, ships[]} with a ship entry per unit type of the fleet, in unit order.
     *
     * @param array<int|string, BattleFleet> $fleets
     * @return list<array{name: int|string, ships: list<array<string, mixed>>}>
     */
    private function engineFleets(array $fleets): array
    {
        $pricelist = $this->state->pricelist;
        $CombatCaps = $this->state->CombatCaps;

        $list = [];
        foreach ($fleets as $fleetID => $fleet) {
            $techs = $fleet->techs;
            $ships = [];
            foreach ($fleet->units as $unit => $amount) {
                $ships[] = [
                    'shipid'    => (int)$unit,
                    'attack'    => $CombatCaps[$unit]['attack'] * $techs->attackMultiplier(),
                    'hull'      => ($pricelist[$unit]['cost'][901] + $pricelist[$unit]['cost'][902]) / 10 * $techs->armorMultiplier(),
                    'shield'    => $CombatCaps[$unit]['shield'] * $techs->shieldMultiplier(),
                    'bigshield' => (bool)$CombatCaps[$unit]['bigshield'],
                    'stun'      => $CombatCaps[$unit]['stun'],
                    'explode'   => floor((int)$unit / 100) == 2 ? 0.7 : 0, //TODO: move to database
                    'amount'    => (int)$amount,
                    'rapidfire' => $CombatCaps[$unit]['sd'] ?? new \stdClass(),
                ];
            }
            $list[] = ['name' => $fleetID, 'ships' => $ships];
        }

        return $list;
    }

    /**
     * Applies the engine output: per-fleet losses and kill statistics from the round log, remaining units, debris
     * (fleet_debris_percentage for ships, def_debris_percentage for defenses), defense rebuild (60-80%, or 70% per
     * unit for 10 or fewer losses), wreck field, per-user lost value and transport capacity; writes advanced stats
     * unless $sim. The result has the same content as BattleEngineSteemNova::fight().
     * @param array<int|string, BattleFleet> $attackers the fleets that were sent; their units are reduced to the survivors
     * @param array<int|string, BattleFleet> $defenders likewise; the planet also gets its rebuilt defense back
     * @param bool $sim true for simulator runs: nothing is persisted.
     */
    private function parseRustJson(string $json, array $attackers, array $defenders, int $universe, bool $sim, float $executionTime): BattleResult
    {
        $pricelist = $this->state->pricelist;
        $reslist = $this->state->reslist;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $config = $this->configs->get($universe);

        $decoded = json_decode($json, true);

        if ($this->seed !== null) {
            // continue the random stream where the PHP engine would stand after this fight (see useSeed())
            mt_srand($this->seed);
            for ($draw = 0, $draws = (int) ($decoded['rngDraws'] ?? 0); $draw < $draws; $draw++) {
                mt_rand();
            }
        }

        switch ($decoded['outcome']) {
            case 1:
                $won = ATTACKER;
                break;
            case -1:
                $won = DEFENDER;
                break;
            case 0:
                $won = DRAW;
                break;
        }

        $rounds = $decoded['rounds'];

        // Snapshot for the report before mission cases unset() fully destroyed
        // fleets from $attackers/$defenders (keys must match Roundstats fleet IDs)
        $rounds[0]['attackers'] = BattleFleet::listToArrays($attackers);
        $rounds[0]['defenders'] = BattleFleet::listToArrays($defenders);

        $stats = [
            'attacker' => [],
            'defender' => [],
        ];

        foreach ($rounds as $roundNumber => $round) {
            foreach ($round['attacker']['Roundstats'] as $fleetID => $attacker) {
                foreach ($attacker as $element => $ship) {
                    if ($ship['lost'] > 0) {
                        $stats['attacker'][$fleetID]['lost'][$element] = ($stats['attacker'][$fleetID]['lost'][$element] ?? 0) + $ship['lost'];
                    }
                }
            }

            foreach ($round['defender']['Roundstats'] as $fleetID => $defender) {
                foreach ($defender as $element => $ship) {#
                    if ($ship['lost'] > 0) {
                        $stats['defender'][$fleetID]['lost'][$element] = ($stats['defender'][$fleetID]['lost'][$element] ?? 0) + $ship['lost'];
                    }
                }
            }

            //$round['attacker']['Statistics']['ship_destroyed'];

            /**
             * "ship_destroyed" => array:1 [
             * 1 => array:1 [ from
             * 2 => array:1 [ to
             * 207 => array:1 [ from element
             * 206 => 1.0 -> to element amount
             * ]
             * ]
             * ]
             * ]
             */

            foreach ($round['attacker']['Statistics']['ship_destroyed']['maps'] as $fromFleetID => $fromFleet) {
                foreach ($fromFleet as $toFleetID => $toFleet) {
                    foreach ($toFleet as $fromElement => $toElements) {
                        foreach ($toElements as $toElement => $amount) {
                            if ($amount > 0) {
                                $stats['attacker'][$fromFleetID]['destroyed'][$toElement] = ($stats['attacker'][$fromFleetID]['destroyed'][$toElement] ?? 0) + $amount;
                            }
                        }
                    }
                }
            }

            foreach ($round['attacker']['Statistics']['explosion_triggered']['maps'] as $fromFleetID => $fromFleet) {
                foreach ($fromFleet as $toFleetID => $toFleet) {
                    foreach ($toFleet as $fromElement => $toElements) {
                        foreach ($toElements as $toElement => $amount) {
                            if ($amount > 0) {
                                $stats['attacker'][$fromFleetID]['destroyed'][$toElement] = ($stats['attacker'][$fromFleetID]['destroyed'][$toElement] ?? 0) + $amount;
                            }
                        }
                    }
                }
            }

            foreach ($round['defender']['Statistics']['ship_destroyed']['maps'] as $fromFleetID => $fromFleet) {
                foreach ($fromFleet as $toFleetID => $toFleet) {
                    foreach ($toFleet as $fromElement => $toElements) {
                        foreach ($toElements as $toElement => $amount) {
                            if ($amount > 0) {
                                $stats['defender'][$fromFleetID]['destroyed'][$toElement] = ($stats['defender'][$fromFleetID]['destroyed'][$toElement] ?? 0) + $amount;
                            }
                        }
                    }
                }
            }

            foreach ($round['defender']['Statistics']['explosion_triggered']['maps'] as $fromFleetID => $fromFleet) {
                foreach ($fromFleet as $toFleetID => $toFleet) {
                    foreach ($toFleet as $fromElement => $toElements) {
                        foreach ($toElements as $toElement => $amount) {
                            if ($amount > 0) {
                                $stats['defender'][$fromFleetID]['destroyed'][$toElement] = ($stats['defender'][$fromFleetID]['destroyed'][$toElement] ?? 0) + $amount;
                            }
                        }
                    }
                }
            }
        }

        $debris = [
            'attacker' => [
                901 => 0,
                902 => 0,
            ],
            'defender' => [
                901 => 0,
                902 => 0,
            ],
        ];
        // restore defense (70% +/- 20%)
        $repairedDef = [];
        // wreckfield ships
        $wreckfield = [];

        $totalLost = ['attacker' => 0, 'defender' => 0];
        $unitsLost = ['attacker' => [], 'defender' => []];
        $capacityLost = ['attacker' => [], 'defender' => []];
        // every participating user is listed, also without losses (as the PHP engine does)
        foreach (['attacker' => $attackers, 'defender' => $defenders] as $side => $fleets) {
            foreach ($fleets as $fleet) {
                $unitsLost[$side][$fleet->userId()] ??= 0;
                $capacityLost[$side][$fleet->userId()] ??= 0;
            }
        }

        // PlayerID for AI is 0

        foreach ($stats['attacker'] as $fleetID => $attacker) {
            foreach ($attacker['lost'] ?? [] as $element => $amount) {
                if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                    if ($defenders[0]->userId() === 0) { // PlayerID for AI is 0
                        // Expo
                        if (!$sim) {
                            $statsHandler->updateExpoLostAdvancedStats($attackers[$fleetID]->userId(), [$element => $amount]);
                        }
                    } else {
                        // Attack
                        if (!$sim) {
                            $statsHandler->updateLostAdvancedStats($attackers[$fleetID]->userId(), [$element => $amount]);
                        }
                    }

                    $attackers[$fleetID]->units[$element] -= $amount;

                    $debris['attacker'][RESOURCE_METAL] += $pricelist[$element]['cost'][RESOURCE_METAL] * $amount * ($config->fleet_debris_percentage / 100);
                    $debris['attacker'][RESOURCE_CRYSTAL] += $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * $amount * ($config->fleet_debris_percentage / 100);

                    $totalLost['attacker'] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                    $unitsLost['attacker'][$attackers[$fleetID]->userId()] ??= 0;
                    $unitsLost['attacker'][$attackers[$fleetID]->userId()] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                    $capacityLost['attacker'][$attackers[$fleetID]->userId()] ??= 0;
                    $capacityLost['attacker'][$attackers[$fleetID]->userId()] += $amount * $pricelist[$element]['capacity'];
                }
            }

            if (!$sim && $defenders[0]->userId() !== 0) {
                foreach ($attacker['destroyed'] ?? [] as $element => $amount) {
                    if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                        $statsHandler->updateDestroyedAdvancedStats($attackers[$fleetID]->userId(), 0, $element, $amount);
                    }
                }
            }
        }

        // wreckfield requirements
        $defendingPlayer = [
            'lost' => 0,
        ];

        // the defense rebuild rolls in the order of the PHP engine: units in the order of the fleet, not of the first loss
        foreach ($stats['defender'] as $fleetID => $defender) {
            if (isset($defender['lost'], $rounds[0]['defenders'][$fleetID]['unit'])) {
                $ordered = [];
                foreach (array_keys($rounds[0]['defenders'][$fleetID]['unit']) as $element) {
                    if (isset($defender['lost'][$element])) {
                        $ordered[$element] = $defender['lost'][$element];
                    }
                }
                $stats['defender'][$fleetID]['lost'] = $ordered + $defender['lost'];
            }
        }

        foreach ($stats['defender'] as $fleetID => $defender) {
            foreach ($defender['lost'] ?? [] as $element => $amount) {
                if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                    if (!$sim) {
                        $statsHandler->updateLostAdvancedStats($defenders[$fleetID]->userId(), [$element => $amount]);
                    }
                }
                $defenders[$fleetID]->units[$element] -= $amount;

                $giveback = 0;
                if ($fleetID === 0) {
                    if ($element < 300 && $this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK)) { // fleet
                        $wreckfield[$element] = floor($amount * (1 - ($config->fleet_debris_percentage / 100)));
                        $defendingPlayer['lost'] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                    }

                    if ($element >= 400) { // defence
                        if ($amount > 10) {
                            $giveback = ceil($amount * (rand(60, 80) / 100));
                        } else {
                            for ($i = 0; $i < $amount; $i++) {
                                if (rand(1, 100) <= 70) {
                                    $giveback += 1;
                                }
                            }
                        }
                        $defenders[$fleetID]->units[$element] += $giveback;
                        $stats['defender'][$fleetID]['repaired'][$element] = $giveback;
                        $repairedDef[$element]['units'] = $giveback;
                        $repairedDef[$element]['percent'] = $giveback / $amount * 100;
                        if (!$sim) {
                            $statsHandler->increment($defenders[0]->userId(), AdvancedStatsEnum::getRepaired($element), $giveback);
                        }
                    }
                }

                $debris['defender'][RESOURCE_METAL] += $pricelist[$element]['cost'][RESOURCE_METAL] * ($amount - $giveback) * (($element < 400 ? $config->fleet_debris_percentage : $config->def_debris_percentage) / 100);
                $debris['defender'][RESOURCE_CRYSTAL] += $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($amount - $giveback) * (($element < 400 ? $config->fleet_debris_percentage : $config->def_debris_percentage) / 100);

                $totalLost['defender'] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                if ($element < 300) {
                    $unitsLost['defender'][$defenders[$fleetID]->userId()] ??= 0;
                    $unitsLost['defender'][$defenders[$fleetID]->userId()] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                    $capacityLost['defender'][$defenders[$fleetID]->userId()] ??= 0;
                    $capacityLost['defender'][$defenders[$fleetID]->userId()] += $amount * $pricelist[$element]['capacity'];
                }
            }

            if (!$sim) {
                foreach ($defender['destroyed'] ?? [] as $element => $amount) {
                    if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                        $statsHandler->updateDestroyedAdvancedStats($defenders[$fleetID]->userId(), 0, $element, $amount);
                    }
                }
            }
        }

        if (!$sim && $defenders[0]->userId() != 0) {
            $statsDone = [];
            foreach ($attackers as $fleetID => $attacker) {
                if (isset($statsDone[$attacker->userId()])) {
                    continue; // once per user, not per fleet
                }
                $statsDone[$attacker->userId()] = true;
                $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::KBMETAL, $debris['attacker'][RESOURCE_METAL] + $debris['defender'][RESOURCE_METAL]);
                $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::KBCRYSTAL, $debris['attacker'][RESOURCE_CRYSTAL] + $debris['defender'][RESOURCE_CRYSTAL]);
                $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::DESUNITS, $totalLost['defender']);
                $statsHandler->increment($attacker->userId(), AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['attacker'][$attacker->userId()] ?? 0));
            }
            $statsDone = [];
            foreach ($defenders as $fleetID => $defender) {
                if (isset($statsDone[$defender->userId()])) {
                    continue; // once per user, not per fleet
                }
                $statsDone[$defender->userId()] = true;
                $statsHandler->increment($defender->userId(), AdvancedStatsEnum::KBMETAL, $debris['attacker'][RESOURCE_METAL] + $debris['defender'][RESOURCE_METAL]);
                $statsHandler->increment($defender->userId(), AdvancedStatsEnum::KBCRYSTAL, $debris['attacker'][RESOURCE_CRYSTAL] + $debris['defender'][RESOURCE_CRYSTAL]);
                $statsHandler->increment($defender->userId(), AdvancedStatsEnum::DESUNITS, $totalLost['attacker']);
                $statsHandler->increment($defender->userId(), AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['defender'][$defender->userId()] ?? 0));
            }
        }

        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK) ||
            $defenders[0]->userId() === 0 ||
            !isset($defenders[0]->currentTotalFleetState['totalUnits']) ||
            $defenders[0]->currentTotalFleetState['totalUnits'] <= 0 ||
            (!empty($defenders[0]->player['wreckfield_cooldown_until']) && $defenders[0]->player['wreckfield_cooldown_until'] > $this->state->runtime->timestamp) ||
            $defendingPlayer['lost'] < $config->wreckfield_min_units ||
            $defendingPlayer['lost'] / $defenders[0]->currentTotalFleetState['totalUnits'] < ($config->wreckfield_min_percent / 100)) {
            $wreckfield = [];
        }

        return new BattleResult(
            $won,
            new BattleDebris($debris['attacker'][RESOURCE_METAL], $debris['attacker'][RESOURCE_CRYSTAL], $debris['defender'][RESOURCE_METAL], $debris['defender'][RESOURCE_CRYSTAL]),
            $rounds,
            new BattleLosses($totalLost['attacker'], $totalLost['defender'], $unitsLost, $capacityLost),
            $repairedDef,
            $wreckfield,
            microtime(true) - $executionTime,
        );
    }
}