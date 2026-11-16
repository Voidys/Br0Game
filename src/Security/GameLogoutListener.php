<?php

declare(strict_types=1);

namespace App\Security;

use App\Game\Core\Session;
use App\Game\Core\Singleton;
use App\Runtime\TableNames;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LogoutEvent;

/**
 * On logout the game's session goes with the Symfony session: inside a game request the loaded game
 * session object is closed (row, cookie, no write-back at the end of the request), otherwise only its
 * row ({prefix}session: online state, multi-IP checks) is removed.
 */
#[AsEventListener(event: LogoutEvent::class, priority: 64)] // before the firewall invalidates the session (its id still names the row)
final class GameLogoutListener
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TableNames $tables,
        private readonly Singleton $state,
    ) {}

    /** Removes the game session row (and cookie, when the game session is loaded) belonging to the logging-out Symfony session. */
    public function __invoke(LogoutEvent $event): void
    {
        $session = $this->state->session ?? null;
        if ($session instanceof Session) {
            $session->delete();

            return;
        }

        $request = $event->getRequest();
        if ($request->hasSession()) {
            $this->connection->delete($this->tables->get('session'), ['sessionID' => $request->getSession()->getId()]);
        }
    }
}
