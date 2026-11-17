<?php

declare(strict_types=1);

namespace App\Security;

use App\Runtime\GameConfig;

/**
 * How long a login lasts, from the global settings (admin category user_settings):
 * `session_lifetime` (hours of inactivity until the session expires) and `remember_me_days`
 * ("stay logged in" cookie lifetime in days, 0 = the option is off).
 */
final class SessionPolicy
{
    public const DEFAULT_IDLE_HOURS = 12;
    public const DEFAULT_REMEMBER_DAYS = 30;

    public function __construct(private readonly GameConfig $config) {}

    /** idle time in seconds after which a session expires (at least one hour) */
    public function idleLifetime(): int
    {
        return self::idleSeconds($this->setting('session_lifetime', self::DEFAULT_IDLE_HOURS));
    }

    /** seconds of the setting session_lifetime (hours) as read from a game Config */
    public static function idleSeconds(int|string|null $hours): int
    {
        return max(1, (int) ($hours ?? self::DEFAULT_IDLE_HOURS)) * 3600;
    }

    /** lifetime of the "stay logged in" cookie in seconds, 0 when the option is off */
    public function rememberMeLifetime(): int
    {
        return max(0, $this->setting('remember_me_days', self::DEFAULT_REMEMBER_DAYS)) * 86400;
    }

    public function rememberMeDays(): int
    {
        return intdiv($this->rememberMeLifetime(), 86400);
    }

    /** the setting, its default while the database is not reachable (installer) */
    private function setting(string $key, int $default): int
    {
        try {
            return (int) $this->config->get($key, (string) $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}
