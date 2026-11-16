<?php

declare(strict_types=1);

namespace App\Security;

use App\Runtime\TableNames;
use Doctrine\DBAL\Connection;
use Symfony\Component\Security\Core\Exception\UnsupportedUserException;
use Symfony\Component\Security\Core\Exception\UserNotFoundException;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\PasswordUpgraderInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\User\UserProviderInterface;

/**
 * Loads login accounts ({prefix}accounts) by id (session refresh) or e-mail address (login form) and
 * stores rehashed passwords when Symfony's password hasher upgrades an old hash.
 *
 * @implements UserProviderInterface<Account>
 */
final class AccountProvider implements UserProviderInterface, PasswordUpgraderInterface
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TableNames $tables,
    ) {}

    /** Loads an account by numeric id (session refresh) or e-mail address (login); @throws UserNotFoundException when unknown. */
    public function loadUserByIdentifier(string $identifier): Account
    {
        $column = ctype_digit($identifier) ? 'id' : 'email';
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT id, accountname, email, authlevel, lang, password FROM %s WHERE %s = ?', $this->tables->get('accounts'), $column),
            [$column === 'id' ? (int) $identifier : $identifier],
        );

        if ($row === false) {
            $exception = new UserNotFoundException();
            $exception->setUserIdentifier($identifier);
            throw $exception;
        }

        return new Account(
            (int) $row['id'],
            (string) $row['accountname'],
            (string) $row['email'],
            (int) $row['authlevel'],
            (string) $row['lang'],
            (string) $row['password'],
        );
    }

    /** Account by its e-mail address (case-insensitive like the column); no id lookup, so a numeric "e-mail" never names an account. */
    public function loadByEmail(string $email): ?Account
    {
        $row = $this->connection->fetchAssociative(
            sprintf('SELECT id, accountname, email, authlevel, lang, password FROM %s WHERE email = ? ORDER BY id LIMIT 1', $this->tables->get('accounts')),
            [$email],
        );

        return $row === false ? null : new Account(
            (int) $row['id'],
            (string) $row['accountname'],
            (string) $row['email'],
            (int) $row['authlevel'],
            (string) $row['lang'],
            (string) $row['password'],
        );
    }

    /**
     * Reloads the logged-in account from the database on every request so bans, deletions and password changes
     * take effect immediately.
     *
     * @throws UnsupportedUserException for users of other providers
     */
    public function refreshUser(UserInterface $user): Account
    {
        if (!$user instanceof Account) {
            throw new UnsupportedUserException(sprintf('Unsupported user class "%s".', $user::class));
        }

        return $this->loadUserByIdentifier($user->getUserIdentifier());
    }

    public function supportsClass(string $class): bool
    {
        return $class === Account::class;
    }

    /** Stores the new password hash after Symfony rehashed an outdated one at login. */
    public function upgradePassword(PasswordAuthenticatedUserInterface $user, string $newHashedPassword): void
    {
        if ($user instanceof Account) {
            $this->connection->update($this->tables->get('accounts'), ['password' => $newHashedPassword], ['id' => $user->getId()]);
        }
    }
}
