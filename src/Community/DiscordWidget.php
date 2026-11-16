<?php

declare(strict_types=1);

namespace App\Community;

use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Online count of a Discord server for the start page, read from the public server widget
 * (`https://discord.com/api/guilds/<id>/widget.json`; the widget must be enabled in the Discord server settings).
 * Results are cached (cache.app) for SUCCESS_TTL seconds, failures for FAILURE_TTL seconds, so the start page
 * asks Discord at most a few times per hour and never waits longer than the short timeout.
 */
final class DiscordWidget
{
    public const SUCCESS_TTL = 300;
    public const FAILURE_TTL = 120;

    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly CacheInterface $cache,
        private readonly LoggerInterface $logger,
    ) {}

    /** a Discord server ID (snowflake: 17-20 digits) */
    public static function isValidGuildId(string $guildId): bool
    {
        return preg_match('/^\d{17,20}$/', $guildId) === 1;
    }

    /**
     * Widget data of the server, or null without a valid ID or when Discord did not answer (disabled widget, unknown
     * server, timeout).
     *
     * @return array{online: int, invite: ?string}|null
     */
    public function fetch(string $guildId): ?array
    {
        $guildId = trim($guildId);
        if (!self::isValidGuildId($guildId)) {
            return null;
        }

        $data = $this->cache->get('discord_widget_' . $guildId, function (ItemInterface $item) use ($guildId): array {
            $data = $this->request($guildId);
            $item->expiresAfter($data === null ? self::FAILURE_TTL : self::SUCCESS_TTL);

            // an array either way: a cached null would look like a miss to some adapters
            return $data ?? ['failed' => true];
        });

        return isset($data['online']) ? $data : null;
    }

    /** @return array{online: int, invite: ?string}|null */
    private function request(string $guildId): ?array
    {
        try {
            $response = $this->httpClient->request('GET', 'https://discord.com/api/guilds/' . $guildId . '/widget.json', [
                'timeout' => 2,
                'max_duration' => 3,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                return null;
            }
            $json = $response->toArray(false);
        } catch (\Throwable $e) {
            $this->logger->notice('Discord widget request failed: {message}', ['message' => $e->getMessage()]);

            return null;
        }

        if (!isset($json['presence_count']) || !is_int($json['presence_count'])) {
            return null;
        }

        $invite = is_string($json['instant_invite'] ?? null) && str_starts_with($json['instant_invite'], 'https://discord.com/')
            ? $json['instant_invite']
            : null;

        return ['online' => max(0, $json['presence_count']), 'invite' => $invite];
    }
}
