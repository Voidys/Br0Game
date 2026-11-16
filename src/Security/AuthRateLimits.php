<?php

declare(strict_types=1);

namespace App\Security;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Abuse limits of the public forms and of the admin password prompt (limiters in
 * config/packages/framework.yaml, "rate_limiter"). Each call counts one attempt and says whether it
 * is still allowed. The game login is not handled here: it uses the firewall's login_throttling.
 */
final class AuthRateLimits
{
    public function __construct(
        #[Autowire(service: 'limiter.registration')]
        private readonly RateLimiterFactoryInterface $registration,
        #[Autowire(service: 'limiter.lost_password')]
        private readonly RateLimiterFactoryInterface $lostPassword,
        #[Autowire(service: 'limiter.admin_elevation')]
        private readonly RateLimiterFactoryInterface $adminElevation,
        #[Autowire(service: 'limiter.account_settings_password')]
        private readonly RateLimiterFactoryInterface $accountSettingsPassword,
        #[Autowire(service: 'limiter.private_messages')]
        private readonly RateLimiterFactoryInterface $privateMessages,
    ) {}

    /** Registration attempts (successful or not) per client IP. */
    public function allowRegistration(string $ip): bool
    {
        return $this->registration->create($ip)->consume()->isAccepted();
    }

    /** "Lost password" requests per client IP. */
    public function allowLostPassword(string $ip): bool
    {
        return $this->lostPassword->create($ip)->consume()->isAccepted();
    }

    /** Current-password checks on the account settings page (password and e-mail change) per account. */
    public function allowAccountSettingsPassword(int $accountId): bool
    {
        return $this->accountSettingsPassword->create((string) $accountId)->consume()->isAccepted();
    }

    /** Private messages sent or forwarded (one count per send/forward request) per player. */
    public function allowPrivateMessage(int $userId): bool
    {
        return $this->privateMessages->create((string) $userId)->consume()->isAccepted();
    }

    /** Admin password prompt attempts per client IP and account. */
    public function allowAdminElevation(string $ip, int $accountId): bool
    {
        return $this->adminElevation->create($ip . '|' . $accountId)->consume()->isAccepted();
    }
}
