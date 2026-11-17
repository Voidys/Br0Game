<?php

namespace App\Game\Enum;

/**
 * Alliance diplomacy levels as stored in `diplo.level`.
 */
enum DiploType: int
{
    case Main = 0;
    case Wing = 1;
    case AlliancePact = 2;
    case TradePact = 3;
    case NonAgressionPact = 4;
    case War = 5;
    case SecretAlliancePact = 6;
    case Peace = 7;
}
