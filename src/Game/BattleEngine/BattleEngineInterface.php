<?php
namespace App\Game\BattleEngine;

interface BattleEngineInterface
{
    /**
     * @param array $attackers Wird by-ref mutiert (Units werden reduziert, etc.)
     * @param array $defenders Wird by-ref mutiert
     * @return array Battle-Result (won,debris,rw,unitLost,repaired,wreckfield,duration,...)
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