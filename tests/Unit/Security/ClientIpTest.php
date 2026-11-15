<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\SessionManager;
use App\Game\Core\Singleton;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;

/**
 * The game's client IP (SessionManager::getClientIp()) is Request::getClientIp(): forwarding headers count
 * only from configured trusted proxies (framework.trusted_proxies), never from arbitrary clients.
 */
final class ClientIpTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);
    }

    private function ipFor(?Request $request): string
    {
        $state = new Singleton(new RuntimeContext(dirname(__DIR__, 3)));
        if ($request !== null) {
            $state->request = $request;
        }
        $manager = new SessionManager($this->createStub(Database::class), $this->createStub(ConfigRegistry::class), $state);

        return $manager->getClientIp();
    }

    /** @param array<string, string> $headers */
    private function request(string $remoteAddr, array $headers): Request
    {
        $server = ['REMOTE_ADDR' => $remoteAddr];
        foreach ($headers as $name => $value) {
            $server['HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create('/', 'GET', [], [], [], $server);
    }

    public function testForwardingHeadersAreIgnoredWithoutTrustedProxies(): void
    {
        $request = $this->request('203.0.113.9', ['X-Forwarded-For' => '198.51.100.7', 'Client-IP' => '198.51.100.8']);

        self::assertSame('203.0.113.9', $this->ipFor($request));
    }

    public function testForwardedAddressIsUsedBehindATrustedProxy(): void
    {
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);

        self::assertSame('198.51.100.7', $this->ipFor($this->request('10.0.0.1', ['X-Forwarded-For' => '198.51.100.7'])));
        // a client that is not the proxy cannot forge it
        self::assertSame('203.0.113.9', $this->ipFor($this->request('203.0.113.9', ['X-Forwarded-For' => '198.51.100.7'])));
    }

    public function testGarbageNeverReachesTheDatabase(): void
    {
        Request::setTrustedProxies(['10.0.0.1'], Request::HEADER_X_FORWARDED_FOR);
        $ip = $this->ipFor($this->request('10.0.0.1', ['X-Forwarded-For' => '<script>alert(1)</script>']));

        self::assertTrue(filter_var($ip, FILTER_VALIDATE_IP) !== false || $ip === 'UNKNOWN');
        self::assertStringNotContainsString('<', $ip);
    }

    public function testWithoutRequestTheAddressIsUnknown(): void
    {
        self::assertSame('UNKNOWN', $this->ipFor(null));
    }
}
