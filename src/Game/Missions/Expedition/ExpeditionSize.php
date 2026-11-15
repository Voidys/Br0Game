<?php

namespace App\Game\Missions\Expedition;

/**
 * Size of an expedition event card ("Small", "Medium", "Big") as the numeric values the event handlers work with.
 */
final class ExpeditionSize
{
    /** Convert deck size to category (0 = Small, 1 = Medium, 2 = Big, unknown sizes count as Big). */
    public static function category(string $size): int
    {
        switch ($size) {
            case 'Small':
                return 0;
            case 'Medium':
                return 1;
            case 'Big':
            default:
                return 2;
        }
    }

    /** Convert deck size to the speed factor of the Fast/Slow events (Small = 2, Medium = 3, Big = 5, unknown = 2). */
    public static function speedFactor(string $size): int
    {
        switch ($size) {
            case 'Small':
                return 2;
            case 'Medium':
                return 3;
            case 'Big':
                return 5;
            default:
                return 2;
        }
    }
}
