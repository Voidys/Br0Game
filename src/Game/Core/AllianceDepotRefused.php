<?php

declare(strict_types=1);

namespace App\Game\Core;

/**
 * An Alliance Depot supply that cannot be done; the message is the language key (domain ingame) the page shows.
 */
final class AllianceDepotRefused extends \RuntimeException
{
    /**
     * @param list<int|string> $arguments values for the placeholders of the text
     */
    public function __construct(string $langKey, public readonly array $arguments = [])
    {
        parent::__construct($langKey);
    }
}
