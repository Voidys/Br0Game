<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Controller\ProofOfWorkController;
use App\Security\BotProtection;
use App\Security\PowRisk;
use App\Security\ProofOfWork;
use App\Tests\Support\GameTestCase;

/** The bot check as wired in the container: challenge endpoint, settings, single use, refusals. */
final class ProofOfWorkTest extends GameTestCase
{
    /** @param array<string, mixed> $challenge */
    private function payload(array $challenge, ?int $number = null): string
    {
        for ($n = 0; $number === null && $n <= $challenge['maxnumber']; $n++) {
            if (hash('sha256', $challenge['salt'] . $n) === $challenge['challenge']) {
                $number = $n;
            }
        }

        return base64_encode((string) json_encode([
            'salt' => $challenge['salt'],
            'challenge' => $challenge['challenge'],
            'signature' => $challenge['signature'],
            'number' => $number,
        ]));
    }

    /** a random address in 10.x.y.0/24 (the risk counters live in the cache, outside the test transaction) */
    private function freshIp(?string $prefix = null, int $host = 0): string
    {
        return ($prefix ?? '10.' . random_int(1, 254) . '.' . random_int(1, 254)) . '.' . ($host ?: random_int(1, 254));
    }

    private function protection(): BotProtection
    {
        return self::container()->get(BotProtection::class);
    }

    public function testEndpointIssuesAChallengeWithTheConfiguredDifficulty(): void
    {
        $response = (self::container()->get(ProofOfWorkController::class))(\Symfony\Component\HttpFoundation\Request::create('/pow-challenge', 'GET', [], [], [], ['REMOTE_ADDR' => $this->freshIp()]));
        $challenge = json_decode((string) $response->getContent(), true);

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(['salt', 'challenge', 'signature', 'maxnumber', 'wait'], array_keys($challenge));
        self::assertSame(25000, $challenge['maxnumber']);
        self::assertSame(3, $challenge['wait']);
    }

    public function testValidSolutionPassesOnceAndReplayIsRefused(): void
    {
        $proof = self::container()->get(ProofOfWork::class);
        $challenge = $proof->issue(800, 3, time() - 10);
        $payload = $this->payload($challenge);

        self::assertTrue($this->protection()->passes($payload, '', $this->freshIp()));
        self::assertFalse($this->protection()->passes($payload, '', $this->freshIp()));
    }

    public function testRefusals(): void
    {
        $proof = self::container()->get(ProofOfWork::class);
        $old = time() - 10;

        // honeypot filled
        self::assertFalse($this->protection()->passes($this->payload($proof->issue(800, 3, $old)), 'bot'));
        // no solution at all, and a wrong one
        self::assertFalse($this->protection()->passes('', ''));
        self::assertFalse($this->protection()->passes($this->payload($proof->issue(800, 3, $old), 0) . 'x', ''));
        // tampered signature
        $challenge = $proof->issue(800, 3, $old);
        $challenge['signature'] = str_repeat('a', 64);
        self::assertFalse($this->protection()->passes($this->payload($challenge), ''));
        // expired (issued more than the lifetime ago)
        self::assertFalse($this->protection()->passes($this->payload($proof->issue(800, 3, time() - ProofOfWork::LIFETIME - 5)), ''));
        // too fast: issued just now, minimum fill time is 3 seconds
        self::assertFalse($this->protection()->passes($this->payload($proof->issue(800, 3, time())), ''));
    }

    private function failures(string $ip, int $times): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->protection()->passes('', '', $ip);
        }
    }

    public function testDifficultyRisesForSuspiciousIpsAndPrefixes(): void
    {
        $prefix = '10.' . random_int(1, 254) . '.' . random_int(1, 254);
        $base = 25000;

        // one IP: three failures make it elevated (x20), ten high (x100, capped)
        $a = $this->freshIp($prefix, 1);
        self::assertSame($base, $this->protection()->challenge($a)['maxnumber']);
        $this->failures($a, 3);
        $elevated = $this->protection()->challenge($a);
        self::assertSame($base * 20, $elevated['maxnumber']);
        self::assertGreaterThan(3, $elevated['wait']);
        $this->failures($a, 7);
        self::assertSame(1500000, $this->protection()->challenge($a)['maxnumber']);

        // the /24: 9 failures (3x) in total, spread over addresses below their own threshold
        $this->failures($this->freshIp($prefix, 2), 2);
        $this->failures($this->freshIp($prefix, 3), 2);
        $this->failures($this->freshIp($prefix, 4), 2);
        $neighbour = $this->freshIp($prefix, 99);
        self::assertSame(PowRisk::TIER_ELEVATED, $this->protection()->tier($neighbour), 'the prefix holds 16 failures, above its elevated threshold of 9');
        self::assertSame($base * 20, $this->protection()->challenge($neighbour)['maxnumber']);

        // another /24 is untouched
        $other = '10.' . random_int(1, 254) . '.' . (((int) explode('.', $prefix)[2] + 1) % 250 + 1);
        self::assertSame($base, $this->protection()->challenge($this->freshIp($other, 5))['maxnumber']);
    }

    public function testLoweringTheSignedMaximumIsRefused(): void
    {
        $ip = $this->freshIp();
        $this->failures($ip, 3);
        $challenge = $this->protection()->challenge($ip);
        self::assertSame(500000, $challenge['maxnumber']);

        $data = json_decode((string) base64_decode($this->payload($challenge), true), true);
        $data['salt'] = preg_replace('/\.500000\./', '.100.', $data['salt']);
        $data['challenge'] = hash('sha256', $data['salt'] . $data['number']);

        self::assertFalse($this->protection()->passes(base64_encode((string) json_encode($data)), '', $ip));
    }

    public function testAdaptiveModeCanBeSwitchedOff(): void
    {
        $this->db->insert("INSERT INTO %%CONFIG%% (`key`, `value`) VALUES ('pow_adaptive', '0') ON DUPLICATE KEY UPDATE `value` = '0';", []);
        $this->configs->reload();
        $ip = $this->freshIp();
        $this->failures($ip, 12);

        self::assertSame(PowRisk::TIER_NORMAL, $this->protection()->tier($ip));
        self::assertSame(25000, $this->protection()->challenge($ip)['maxnumber']);
    }
}
