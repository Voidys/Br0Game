<?php

declare(strict_types=1);

namespace App\Game\BattleEngine;

/**
 * The two sides of a fight. The backing value is the key the engines, the rust library and the report use
 * ('attacker' / 'defender').
 */
enum BattleSide: string
{
    case Attacker = 'attacker';
    case Defender = 'defender';
}
