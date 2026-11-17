<?php

declare(strict_types=1);

namespace App\Controller\Game;

/**
 * Ends the nested checks of the galaxy shortcut request (FleetAjaxController) with its JSON answer; null data = no answer
 * (the client is gone).
 */
final class FleetAjaxAnswer extends \RuntimeException
{
    /** @param array<string, mixed>|null $data the answer {code, mess, slots, ...} */
    public function __construct(public readonly ?array $data)
    {
        parent::__construct('fleet ajax answer');
    }
}
