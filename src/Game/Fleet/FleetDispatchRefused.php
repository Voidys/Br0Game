<?php

declare(strict_types=1);

namespace App\Game\Fleet;

/**
 * A fleet dispatch was refused: carries the translated message and the buttons of the message page (label, url).
 */
final class FleetDispatchRefused extends \RuntimeException
{
    /** @param list<array{label: string, url: string}> $buttons */
    public function __construct(string $message, public readonly array $buttons)
    {
        parent::__construct($message);
    }
}
