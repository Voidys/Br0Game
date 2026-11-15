<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\Config;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Discord;
use App\Game\Core\Singleton;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Pins the Discord webhook request (POST, JSON payload with game name as username) and that a failing webhook
 * never throws (the error reporter uses this class).
 */
final class DiscordTest extends TestCase
{
    /** @var list<array{method: string, url: string, options: array<string, mixed>}> */
    private array $requests = [];

    private function discord(MockResponse $response, string $hook = 'https://discord.test/hook'): Discord
    {
        $registry = $this->createStub(ConfigRegistry::class);
        $config = new Config(['game_name' => 'Br0Game', 'discord_logs_hook' => $hook], $registry);
        $registry->method('get')->willReturn($config);
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response;
        });

        return new Discord($registry, new Singleton(new RuntimeContext(dirname(__DIR__, 2))), $http);
    }

    public function testSendLogPostsJsonPayload(): void
    {
        $this->discord(new MockResponse('', ['http_code' => 204]))->sendLog('cron', ['successful' => true, 'list' => [1, 2]]);

        self::assertCount(1, $this->requests);
        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame('https://discord.test/hook', $this->requests[0]['url']);
        self::assertContains('Content-type: application/json', $this->requests[0]['options']['headers']);
        self::assertSame(
            ['content' => "**cron**\n```successful: 1\nlist: [1,2]\n```", 'username' => 'Br0Game', 'tts' => false, 'embeds' => []],
            json_decode($this->requests[0]['options']['body'], true),
        );
    }

    public function testFailingWebhookDoesNotThrow(): void
    {
        $this->discord(new MockResponse('', ['error' => 'connection refused']))->sendLog('cron', ['a' => 'b']);

        self::assertCount(1, $this->requests);
    }

    public function testNoHookConfiguredSendsNothing(): void
    {
        $this->discord(new MockResponse(''), '')->sendLog('cron', ['a' => 'b']);

        self::assertSame([], $this->requests);
    }
}
