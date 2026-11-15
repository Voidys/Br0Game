<?php

declare(strict_types=1);

namespace App\Security;

use App\Runtime\GameConfig;
use App\Runtime\TableNames;
use Doctrine\DBAL\Connection;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\PasswordUpgradeBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Credentials\PasswordCredentials;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\EntryPoint\AuthenticationEntryPointInterface;

/**
 * The game login (POST /?page=login from the start page): e-mail + password checked by Symfony's
 * password hasher (bcrypt hashes of the accounts table), with the game's rules around it:
 * failed logins are counted per account (code=1, from the 5th attempt code=4), accounts that still
 * wait for their e-mail verification are sent to the verification link when the universe does not
 * require it, and visitors without login are sent to the start page (code=3).
 */
final class LoginFormAuthenticator extends AbstractAuthenticator implements AuthenticationEntryPointInterface
{
    private const ROUTES = ['game_index_login', 'game_index_login_uni'];

    public function __construct(
        private readonly AccountProvider $accounts,
        private readonly Connection $connection,
        private readonly TableNames $tables,
        private readonly GameConfig $config,
    ) {}

    public function supports(Request $request): bool
    {
        return $request->isMethod('POST') && in_array($request->attributes->get('_route'), self::ROUTES, true);
    }

    public function authenticate(Request $request): Passport
    {
        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');

        return new Passport(
            new UserBadge($email, $this->accounts->loadUserByIdentifier(...)),
            new PasswordCredentials($password),
            [new PasswordUpgradeBadge($password, $this->accounts)],
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $account = $token->getUser();
        if ($account instanceof Account) {
            $this->connection->update($this->tables->get('accounts'), ['failed_logins' => 0], ['id' => $account->getId()]);
        }

        $response = new RedirectResponse($this->directory($request) . 'account');
        // remembers that this browser played before (start page shows the login form first)
        $response->headers->setCookie(Cookie::create('account', 'account', 2147483647, '/', httpOnly: false));

        return $response;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        $email = trim((string) $request->request->get('email', ''));
        $password = (string) $request->request->get('password', '');

        $account = $this->connection->fetchAssociative(
            sprintf('SELECT id, failed_logins FROM %s WHERE email = ?', $this->tables->get('accounts')),
            [$email],
        );
        if ($account !== false) {
            return $this->countFailure($request, $this->tables->get('accounts'), 'id', $account['id'], (int) $account['failed_logins']);
        }

        // registered but not verified yet
        $pending = $this->connection->fetchAssociative(
            sprintf('SELECT validationID, validationKey, password, failed_logins FROM %s WHERE email = ?', $this->tables->get('accounts_valid')),
            [$email],
        );
        if ($pending !== false && !$this->config->getBool('user_valid')) {
            if (password_verify($password, (string) $pending['password'])) {
                return new RedirectResponse($this->directory($request) . '?page=vertify&i=' . $pending['validationID'] . '&k=' . $pending['validationKey']);
            }

            return $this->countFailure($request, $this->tables->get('accounts_valid'), 'validationID', $pending['validationID'], (int) $pending['failed_logins']);
        }

        return new RedirectResponse($this->directory($request) . '?code=1');
    }

    /** visitors without login on a protected page: start page with "session expired" */
    public function start(Request $request, ?AuthenticationException $authException = null): Response
    {
        return new RedirectResponse($this->directory($request) . '?code=3');
    }

    private function countFailure(Request $request, string $table, string $idColumn, int|string $id, int $failed): RedirectResponse
    {
        if ($failed < 4) {
            $this->connection->executeStatement(
                sprintf('UPDATE %s SET failed_logins = failed_logins + 1 WHERE %s = ?', $table, $idColumn),
                [$id],
            );

            return new RedirectResponse($this->directory($request) . '?code=1');
        }

        return new RedirectResponse($this->directory($request) . '?code=4');
    }

    /** directory of the requested page: "/" or "/uniN/" (links stay inside the universe prefix) */
    private function directory(Request $request): string
    {
        $path = $request->getPathInfo();

        return $request->getBasePath() . (preg_match('#^/uni\d+/#', $path, $match) ? $match[0] : '/');
    }
}
