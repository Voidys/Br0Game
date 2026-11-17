<?php

namespace App\Game\Enum;

/**
 * Message categories as stored in `messages.message_type` (All, Favorites, Search are list filters, not stored types).
 */
enum MessageType: int
{
    case SpyReport = 0;
    case User = 1;
    case Alliance = 2;
    case BattleReport = 3;
    case System = 4;
    case Transport = 5;
    case ForeignTransport = 6;
    case SpyDefence = 7;
    case Expedition = 15;
    case Game = 50;
    case BuildList = 99;
    case All = 100;
    case Favorites = 998;
    case Search = 999;
}
