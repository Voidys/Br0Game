<?php

namespace App\Game\Enum;

/**
 * Staff levels as stored in `users.authlevel`.
 */
enum AuthLevel: int
{
    case User = 0;
    case Moderator = 1;
    case Operator = 2;
    case Admin = 3;
}
