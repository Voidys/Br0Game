<?php

namespace App\Game\Enum;

/**
 * Planet kinds as stored in `planets.planet_type` and the fleet start/end types (debris fields are type 2, pirate bases 4).
 */
enum PlanetType: int
{
    case Planet = 1;
    case Debris = 2;
    case Moon = 3;
    case PirateBase = 4;
}
