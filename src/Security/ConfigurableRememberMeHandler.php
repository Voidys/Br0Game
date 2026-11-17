<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\PropertyAccess\PropertyAccess;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Signature\SignatureHasher;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Http\RememberMe\RememberMeDetails;
use Symfony\Component\Security\Http\RememberMe\RememberMeHandlerInterface;
use Symfony\Component\Security\Http\RememberMe\SignatureRememberMeHandler;

/**
 * "Stay logged in" (firewall remember_me, security.yaml): Symfony's signed remember-me cookie, with the lifetime
 * from SessionPolicy (admin setting remember_me_days) instead of a fixed container option. The signature covers the
 * password hash, so a password change or reset ends every remembered login. With the option off (0 days) no cookie is
 * issued and existing ones are rejected (and cleared by the authenticator).
 */
final class ConfigurableRememberMeHandler implements RememberMeHandlerInterface
{
    public const COOKIE = 'REMEMBERME';

    private ?SignatureRememberMeHandler $inner = null;

    public function __construct(
        private readonly SessionPolicy $policy,
        private readonly AccountProvider $accounts,
        private readonly RequestStack $requestStack,
        #[Autowire('%kernel.secret%')]
        private readonly string $secret,
    ) {}

    public function createRememberMeCookie(UserInterface $user): void
    {
        if ($this->policy->rememberMeLifetime() > 0) {
            $this->inner()->createRememberMeCookie($user);
        }
    }

    /** Account of a valid cookie (signature, expiry, password hash checked by the inner handler); refused while the option is off. */
    public function consumeRememberMeCookie(RememberMeDetails $rememberMeDetails): UserInterface
    {
        if ($this->policy->rememberMeLifetime() === 0) {
            throw new AuthenticationException('Remember-me is disabled.');
        }

        return $this->inner()->consumeRememberMeCookie($rememberMeDetails);
    }

    public function clearRememberMeCookie(): void
    {
        $this->inner()->clearRememberMeCookie();
    }

    /** Symfony's signature handler with the current lifetime from SessionPolicy, built on first use (cookie options as in security.yaml). */
    private function inner(): SignatureRememberMeHandler
    {
        return $this->inner ??= new SignatureRememberMeHandler(
            new SignatureHasher(PropertyAccess::createPropertyAccessor(), ['password'], $this->secret),
            $this->accounts,
            $this->requestStack,
            [
                'name' => self::COOKIE,
                'lifetime' => max(1, $this->policy->rememberMeLifetime()),
                'path' => '/',
                'domain' => null,
                'secure' => null,
                'httponly' => true,
                'samesite' => 'lax',
            ],
        );
    }
}
