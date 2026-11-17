<?php

namespace App\Game\Enum;

/**
 * Fleet mission ids as stored in `fleets.fleet_mission` (rows keep plain ints; use `Mission::tryFrom()` on DB values).
 */
enum Mission: int
{
    case Attack = 1;
    case Acs = 2;
    case Transport = 3;
    case Station = 4;
    case Hold = 5;
    case Spy = 6;
    case Colonisation = 7;
    case Recycling = 8;
    case Destruction = 9;
    case Missile = 10;
    case Expedition = 15;
    case Trade = 16;
    case Transfer = 17;
    case PiratebaseAttack = 20;
    case PiratebaseSpy = 21;
    case PiratebaseRecycling = 22;
}
