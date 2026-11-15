<?php

namespace App\Game\BattleEngine;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Config;
use App\Game\Core\Singleton;

/**
 * Rust battle engine. bin/battlerust (a small launcher built from tools/battlerust-cli/) loads
 * bin/libbattlerust.so and exchanges the battle as JSON over stdin/stdout: createRustJson() builds the input,
 * parseRustJson() applies the result. No PHP extension needed; one process start (about 2 ms) per battle.
 * Linux x86_64 only; elsewhere BattleHandler falls back to the PHP engine.
 */
class BattleEngineRust extends AbstractBattleEngine
{
    public function getName(): string
    {
        // stored with every report: 'rust' reports are compressed and use the rust report template
        return 'rust';
    }

    public function isAvailable(bool $sim = false): bool
    {
        return \function_exists('proc_open')
            && \PHP_OS_FAMILY === 'Linux'
            && \is_executable(self::binary())
            && \is_file(\App\Runtime\ProjectPaths::ROOT . 'bin/libbattlerust.so');
    }

    public function calculateAttack(array &$attackers, array &$defenders, int $universe, bool $sim = false, int $simTries = 1): array|false
    {
        $executionTime = microtime(true);

        $json = $this->createRustJson($attackers, $defenders, $simTries);
        $output = $this->runEngine($json);
        if ($output === null) {
            return false;
        }

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

    private function createRustJson(&$attackers, &$defenders, $simTries = 1): string
    {
        $pricelist =& $this->state->pricelist;
        $CombatCaps =& $this->state->CombatCaps;

        $data = [
            'rounds'   => 6,
            'attacker' => [],
            'defender' => [],
        ];

        foreach ($attackers as $fleetID => $attacker) {
            $attackerData = [
                'name'  => $fleetID,
                'ships' => [],
            ];
            foreach ($attacker['unit'] as $unit => $amount) {
                $unitData = [
                    'shipid'    => (int)$unit,
                    'attack'    => round($CombatCaps[$unit]['attack'] * (1 + (0.1 * $attacker['player']['military_tech'])), 1),
                    'hull'      => round(($pricelist[$unit]['cost'][901] + $pricelist[$unit]['cost'][902]) / 10 * (1 + (0.1 * $attacker['player']['defence_tech']))),
                    'shield'    => round($CombatCaps[$unit]['shield'] * (1 + (0.1 * $attacker['player']['shield_tech']))),
                    'bigshield' => (bool)$CombatCaps[$unit]['bigshield'],
                    'stun'      => $CombatCaps[$unit]['stun'],
                    'explode'   => 0.7,
                    'amount'    => (int)$amount,
                    'rapidfire' => $CombatCaps[$unit]['sd'] ?? new \stdClass(),
                ];
                $attackerData['ships'][] = $unitData;
            }
            $data['attacker'][] = $attackerData;
        }

        foreach ($defenders as $fleetID => $defender) {
            $defenderData = [
                'name'  => $fleetID,
                'ships' => [],
            ];
            foreach ($defender['unit'] as $unit => $amount) {
                $unitData = [
                    'shipid'    => (int)$unit,
                    'attack'    => round($CombatCaps[$unit]['attack'] * (1 + (0.1 * $defender['player']['military_tech'])), 1),
                    'hull'      => round(($pricelist[$unit]['cost'][901] + $pricelist[$unit]['cost'][902]) / 10 * (1 + (0.1 * $defender['player']['defence_tech']))),
                    'shield'    => round($CombatCaps[$unit]['shield'] * (1 + (0.1 * $defender['player']['shield_tech']))),
                    'bigshield' => (bool)$CombatCaps[$unit]['bigshield'],
                    'stun'      => $CombatCaps[$unit]['stun'],
                    'explode'   => floor((int)$unit / 100) == 2 ? 0.7 : 0, //TODO: move to database
                    'amount'    => (int)$amount,
                    'rapidfire' => $CombatCaps[$unit]['sd'] ?? new \stdClass(),
                ];
                $defenderData['ships'][] = $unitData;
            }
            $data['defender'][] = $defenderData;
        }

        return json_encode($data);
    }

    private function parseRustJson($json, &$attackers, &$defenders, $universe, $sim, $executionTime)
    {
        $pricelist =& $this->state->pricelist;
        $reslist =& $this->state->reslist;
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $config = $this->configs->get($universe);

        $decoded = json_decode($json, true);

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
        $rounds[0]['attackers'] = $attackers;
        $rounds[0]['defenders'] = $defenders;

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

        // PlayerID for AI is 0

        foreach ($stats['attacker'] as $fleetID => $attacker) {
            foreach ($attacker['lost'] ?? [] as $element => $amount) {
                if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                    if ($defenders[0]['player']['id'] === 0) { // PlayerID for AI is 0
                        // Expo
                        if (!$sim) {
                            $statsHandler->updateExpoLostAdvancedStats($attackers[$fleetID]['player']['id'], [$element => $amount]);
                        }
                    } else {
                        // Attack
                        if (!$sim) {
                            $statsHandler->updateLostAdvancedStats($attackers[$fleetID]['player']['id'], [$element => $amount]);
                        }
                    }

                    $attackers[$fleetID]['unit'][$element] -= $amount;

                    $debris['attacker'][RESOURCE_METAL] += $pricelist[$element]['cost'][RESOURCE_METAL] * $amount * ($config->fleet_debris_percentage / 100);
                    $debris['attacker'][RESOURCE_CRYSTAL] += $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * $amount * ($config->fleet_debris_percentage / 100);

                    $totalLost['attacker'] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                    $unitsLost['attacker'][$attackers[$fleetID]['player']['id']] ??= 0;
                    $unitsLost['attacker'][$attackers[$fleetID]['player']['id']] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                    $capacityLost['attacker'][$attackers[$fleetID]['player']['id']] ??= 0;
                    $capacityLost['attacker'][$attackers[$fleetID]['player']['id']] += $amount * $pricelist[$element]['capacity'];
                }
            }

            if (!$sim && $defenders[0]['player']['id'] !== 0) {
                foreach ($attacker['destroyed'] ?? [] as $element => $amount) {
                    if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                        $statsHandler->updateDestroyedAdvancedStats($attackers[$fleetID]['player']['id'], 0, $element, $amount);
                    }
                }
            }
        }

        // wreckfield requirements
        $defendingPlayer = [
            'lost' => 0,
        ];

        foreach ($stats['defender'] as $fleetID => $defender) {
            foreach ($defender['lost'] ?? [] as $element => $amount) {
                if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                    if (!$sim) {
                        $statsHandler->updateLostAdvancedStats($defenders[$fleetID]['player']['id'], [$element => $amount]);
                    }
                }
                $defenders[$fleetID]['unit'][$element] -= $amount;

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
                        $defenders[$fleetID]['unit'][$element] += $giveback;
                        $stats['defender'][$fleetID]['repaired'][$element] = $giveback;
                        $repairedDef[$element]['units'] = $giveback;
                        $repairedDef[$element]['percent'] = $giveback / $amount * 100;
                        if (!$sim) {
                            $statsHandler->increment($defenders[0]['player']['id'], AdvancedStatsEnum::getRepaired($element), $giveback);
                        }
                    }
                }

                $debris['defender'][RESOURCE_METAL] += $pricelist[$element]['cost'][RESOURCE_METAL] * ($amount - $giveback) * (($element < 400 ? $config->fleet_debris_percentage : $config->def_debris_percentage) / 100);
                $debris['defender'][RESOURCE_CRYSTAL] += $pricelist[$element]['cost'][RESOURCE_CRYSTAL] * ($amount - $giveback) * (($element < 400 ? $config->fleet_debris_percentage : $config->def_debris_percentage) / 100);

                $totalLost['defender'] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                if ($element < 300) {
                    $unitsLost['defender'][$defenders[$fleetID]['player']['id']] ??= 0;
                    $unitsLost['defender'][$defenders[$fleetID]['player']['id']] += $amount * ($pricelist[$element]['cost'][RESOURCE_METAL] + $pricelist[$element]['cost'][RESOURCE_CRYSTAL]);
                    $capacityLost['defender'][$defenders[$fleetID]['player']['id']] ??= 0;
                    $capacityLost['defender'][$defenders[$fleetID]['player']['id']] += $amount * $pricelist[$element]['capacity'];
                }
            }

            if (!$sim) {
                foreach ($defender['destroyed'] ?? [] as $element => $amount) {
                    if ($element > 0 && (in_array($element, $reslist['fleet']) || in_array($element, $reslist['defense'])) && $amount > 0) {
                        $statsHandler->updateDestroyedAdvancedStats($defenders[$fleetID]['player']['id'], 0, $element, $amount);
                    }
                }
            }
        }

        if (!$sim && $defenders[0]['player']['id'] != 0) {
            $statsDone = [];
            foreach ($attackers as $fleetID => $attacker) {
                if (isset($statsDone[$attacker['player']['id']])) {
                    continue; // once per user, not per fleet
                }
                $statsDone[$attacker['player']['id']] = true;
                $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::KBMETAL, $debris['attacker'][RESOURCE_METAL] + $debris['defender'][RESOURCE_METAL]);
                $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::KBCRYSTAL, $debris['attacker'][RESOURCE_CRYSTAL] + $debris['defender'][RESOURCE_CRYSTAL]);
                $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::DESUNITS, $totalLost['defender']);
                $statsHandler->increment($attacker['player']['id'], AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['attacker'][$attacker['player']['id']] ?? 0));
            }
            $statsDone = [];
            foreach ($defenders as $fleetID => $defender) {
                if (isset($statsDone[$defender['player']['id']])) {
                    continue; // once per user, not per fleet
                }
                $statsDone[$defender['player']['id']] = true;
                $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::KBMETAL, $debris['attacker'][RESOURCE_METAL] + $debris['defender'][RESOURCE_METAL]);
                $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::KBCRYSTAL, $debris['attacker'][RESOURCE_CRYSTAL] + $debris['defender'][RESOURCE_CRYSTAL]);
                $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::DESUNITS, $totalLost['attacker']);
                $statsHandler->increment($defender['player']['id'], AdvancedStatsEnum::LOSTUNITS, max(0, $unitsLost['defender'][$defender['player']['id']] ?? 0));
            }
        }

        if (!$this->viewHelpers()->isModuleAvailable(MODULE_REPAIR_DOCK) ||
            $defenders[0]['player']['id'] === 0 ||
            !isset($defenders[0]['currentTotalFleetState']['totalUnits']) ||
            $defenders[0]['currentTotalFleetState']['totalUnits'] <= 0 ||
            (!empty($defenders[0]['player']['wreckfield_cooldown_until']) && $defenders[0]['player']['wreckfield_cooldown_until'] > $this->state->runtime->timestamp) ||
            $defendingPlayer['lost'] < $config->wreckfield_min_units ||
            $defendingPlayer['lost'] / $defenders[0]['currentTotalFleetState']['totalUnits'] < ($config->wreckfield_min_percent / 100)) {
            $wreckfield = [];
        }

        return [
            'won'                                 => $won,
            'debris'                              => $debris,
            'rw'                                  => $rounds,
            'unitLost'                            => $totalLost,
            'repaired'                            => $repairedDef,
            'wreckfield'                          => $wreckfield,
            'duration'                            => microtime(true) - $executionTime,
            'unitLostPerSideAndUser'              => $unitsLost,
            'transportCapacityLostPerSideAndUser' => $capacityLost,
        ];
    }
}