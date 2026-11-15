<?php

namespace App\Security;

use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * A login account ({prefix}accounts). One account can own players in several universes.
 */
final class Account implements UserInterface, PasswordAuthenticatedUserInterface
{
    /** authlevel => role, see AUTH_* in src/Game/Support/game_constants.php */
    private const ROLES_BY_AUTHLEVEL = [
        1 => 'ROLE_MODERATOR',
        2 => 'ROLE_OPERATOR',
        3 => 'ROLE_ADMIN',
    ];

    public function __construct(
        private readonly int $id,
        private readonly string $accountName,
        private readonly string $email,
        private readonly int $authLevel,
        private readonly string $language,
        #[\SensitiveParameter]
        private readonly ?string $password = null,
    ) {}

    /** bcrypt hash from {prefix}accounts.password (checked by Symfony's password hasher at login) */
    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getAccountName(): string
    {
        return $this->accountName;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function getAuthLevel(): int
    {
        return $this->authLevel;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->id;
    }

    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];
        if (isset(self::ROLES_BY_AUTHLEVEL[$this->authLevel])) {
            $roles[] = self::ROLES_BY_AUTHLEVEL[$this->authLevel];
        }

        return $roles;
    }
}
