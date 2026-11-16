<?php

declare(strict_types=1);

namespace App\Tests\Unit\Bots;

use App\Game\Bots\Llm\LlmException;
use App\Game\Bots\Llm\LlmUrlGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The LLM base URL must never reach internal hosts (SSRF): loopback, private, link-local/metadata, reserved, odd schemes. */
final class LlmUrlGuardTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function blockedUrls(): iterable
    {
        yield 'loopback' => ['http://127.0.0.1:8080/v1'];
        yield 'localhost name' => ['http://localhost/v1'];
        yield 'private 10' => ['https://10.1.2.3/v1'];
        yield 'private 192.168' => ['https://192.168.0.5/v1'];
        yield 'private 172.16' => ['https://172.16.9.9/v1'];
        yield 'metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'cgnat' => ['http://100.64.0.1/'];
        yield 'ipv6 loopback' => ['http://[::1]/'];
        yield 'ipv6 unique local' => ['http://[fd00::1]/'];
        yield 'ipv6 link local' => ['http://[fe80::1]/'];
        yield 'ipv4-mapped metadata' => ['http://[::ffff:169.254.169.254]/'];
        yield 'file scheme' => ['file:///etc/passwd'];
        yield 'ftp scheme' => ['ftp://example.com/'];
        yield 'credentials' => ['https://user:pw@example.com/'];
        yield 'hostname resolving to private' => ['https://internal.example/v1'];
    }

    #[DataProvider('blockedUrls')]
    public function testBlocksInternalTargets(string $url): void
    {
        $guard = new LlmUrlGuard(static fn(string $host): array => $host === 'localhost' ? ['127.0.0.1'] : ['10.0.0.8']);

        $this->expectException(LlmException::class);
        $guard->check($url);
    }

    public function testAllowsPublicHostAndReturnsPinnedAddresses(): void
    {
        $guard = new LlmUrlGuard(static fn(string $host): array => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']);

        self::assertSame(
            ['host' => 'api.example.com', 'ips' => ['93.184.216.34', '2606:2800:220:1:248:1893:25c8:1946']],
            $guard->check('https://API.example.com/v1'),
        );
    }

    public function testOneInternalAnswerBlocksTheHost(): void
    {
        $guard = new LlmUrlGuard(static fn(string $host): array => ['93.184.216.34', '10.0.0.1']);

        $this->expectException(LlmException::class);
        $guard->check('https://rebind.example/');
    }

    public function testUnresolvableHostIsRefused(): void
    {
        $this->expectException(LlmException::class);
        (new LlmUrlGuard(static fn(string $host): array => []))->check('https://nowhere.example/');
    }
}
