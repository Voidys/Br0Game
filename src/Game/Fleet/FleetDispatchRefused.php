<?php

declare(strict_types=1);

namespace App\Game\Fleet;

/**
 * A fleet dispatch was refused: carries the translated message and the buttons of the message page (label, url = route name), or the code of a redirect to the fleet page.
 */
final class FleetDispatchRefused extends \RuntimeException
{
    /** @param list<array{label: string, url: string}> $buttons url is a route name */
    public function __construct(string $message, public readonly array $buttons, public readonly ?int $fleetPageCode = null)
    {
        parent::__construct($message);
    }

    /** the dispatch cannot go on and the player is sent back to the fleet page with this error code (`fl_send_error`) */
    public static function backToFleetPage(int $code): self
    {
        return new self('', [], $code);
    }
}
