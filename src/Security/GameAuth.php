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

    public function account(): ?Account
    {
        $user = $this->security->getUser();

        return $user instanceof Account ? $user : null;
    }

    public function accountId(): int
    {
        return $this->account()?->getId() ?? 0;
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
