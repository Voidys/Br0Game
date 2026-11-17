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
        #[Autowire(service: 'limiter.board_votes')]
        private readonly RateLimiterFactoryInterface $boardVotes,
        #[Autowire(service: 'limiter.board_writes')]
        private readonly RateLimiterFactoryInterface $boardWrites,
        #[Autowire(service: 'limiter.ticket_hour')]
        private readonly RateLimiterFactoryInterface $ticketHour,
        #[Autowire(service: 'limiter.ticket_day')]
        private readonly RateLimiterFactoryInterface $ticketDay,
        #[Autowire(service: 'limiter.alliance_applications')]
        private readonly RateLimiterFactoryInterface $allianceApplications,
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

    /** Board votes per account and day (app.board_votes_per_day); one count per vote request. */
    public function allowBoardVote(int $accountId): bool
    {
        return $this->boardVotes->create((string) $accountId)->consume()->isAccepted();
    }

    /** Board posts and comments per account and day (app.board_writes_per_day); one count per created post or comment. */
    public function allowBoardWrite(int $accountId): bool
    {
        return $this->boardWrites->create((string) $accountId)->consume()->isAccepted();
    }

    /** New support tickets (not answers) per account: 3 per hour and 10 per day; one count per created ticket. */
    public function allowTicket(int $accountId): bool
    {
        return $this->ticketHour->create((string) $accountId)->consume()->isAccepted()
            && $this->ticketDay->create((string) $accountId)->consume()->isAccepted();
    }

    /** Alliance applications per account and day (app.alliance_applications_per_day). */
    public function allowAllianceApplication(int $accountId): bool
    {
        return $this->allianceApplications->create((string) $accountId)->consume()->isAccepted();
    }

    /** Admin password prompt attempts per client IP and account. */
    public function allowAdminElevation(string $ip, int $accountId): bool
    {
        return $this->adminElevation->create($ip . '|' . $accountId)->consume()->isAccepted();
    }
}
