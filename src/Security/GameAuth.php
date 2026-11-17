<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Symfony Security as seen from the game code (reached as $state->auth): the logged-in account,
 * programmatic login (after e-mail verification, after the installation) and logout. The game's
 * own session object (selected universe player, planet, admin second factor) hangs off this login.
 */
final class GameAuth
{
    public function __construct(
        private readonly Security $security,
        private readonly AccountProvider $accounts,
    ) {}

    /** The logged-in login account, null for visitors. */
    public function account(): ?Account
    {
        $user = $this->security->getUser();

        return $user instanceof Account ? $user : null;
    }

    /** Id of the logged-in account, 0 for visitors (the legacy convention for "no account"). */
    public function accountId(): int
    {
        return $this->account()?->getId() ?? 0;
    }

    /** Logs the account in programmatically (e-mail verification, installer): token and session are set up like after the login form. */
    /**
     * Accounts row of the logged-in account as read when the request was authenticated (null for guests); use it
     * only before the page can change the account.
     *
     * @return array<string, mixed>|null
     */
    public function loadedAccountRow(): ?array
    {
        $id = $this->accountId();

        return $id === 0 ? null : $this->accounts->loadedRow($id);
    }

    public function login(int $accountId): void
    {
        $this->security->login($this->accounts->loadUserByIdentifier((string) $accountId), LoginFormAuthenticator::class, 'main');
    }

    /** ends the login (Symfony logout: token, session and the game's session row via GameLogoutListener) */
    public function logout(): void
    {
        if ($this->security->getUser() !== null) {
            $this->security->logout(false);
        }
    }
}
