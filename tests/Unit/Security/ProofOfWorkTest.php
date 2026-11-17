<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\ProofOfWork;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** The proof-of-work verifier on its own: signature, solution, expiry, minimum time, single use, honeypot. */
final class ProofOfWorkTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private ProofOfWork $pow;

    protected function setUp(): void
    {
        $this->pow = new ProofOfWork('unit-test-secret', new ArrayAdapter());
    }

    /**
     * Solves a challenge like the browser does (brute force) and builds the payload.
     *
     * @param array<string, mixed> $challenge
     */
    private function solved(array $challenge): string
    {
        for ($n = 0; $n <= $challenge['maxnumber']; $n++) {
            if (hash('sha256', $challenge['salt'] . $n) === $challenge['challenge']) {
                return base64_encode((string) json_encode([
                    'salt' => $challenge['salt'],
                    'challenge' => $challenge['challenge'],
                    'signature' => $challenge['signature'],
                    'number' => $n,
                ]));
            }
        }
        self::fail('challenge has no solution');
    }

    public function testChallengeShape(): void
    {
        $challenge = $this->pow->issue(1000, 3, self::NOW);

        self::assertSame(['salt', 'challenge', 'signature', 'maxnumber', 'wait'], array_keys($challenge));
        self::assertSame(1000, $challenge['maxnumber']);
        self::assertSame(3, $challenge['wait']);
        self::assertMatchesRegularExpression('/^[0-9a-f]{24}\.' . (self::NOW + 600) . '\.' . self::NOW . '\.1000\.3$/', $challenge['salt']);
        self::assertNotSame($challenge['salt'], $this->pow->issue(1000, 3, self::NOW)['salt']);
    }

    public function testValidSolutionIsAcceptedOnce(): void
    {
        $payload = $this->solved($this->pow->issue(500, 3, self::NOW));

        self::assertNull($this->pow->verify($payload, '', 3, self::NOW + 5));
        self::assertSame(ProofOfWork::REASON_REPLAY, $this->pow->verify($payload, '', 3, self::NOW + 6));
    }

    public function testWrongSolutionAndGarbageAreRefused(): void
    {
        $data = json_decode((string) base64_decode($this->solved($this->pow->issue(500, 0, self::NOW)), true), true);
        $data['number']++;

        self::assertSame(ProofOfWork::REASON_WRONG, $this->pow->verify(base64_encode((string) json_encode($data)), '', 0, self::NOW));
        self::assertSame(ProofOfWork::REASON_MALFORMED, $this->pow->verify('', '', 0, self::NOW));
        self::assertSame(ProofOfWork::REASON_MALFORMED, $this->pow->verify('not base64!', '', 0, self::NOW));
        self::assertSame(ProofOfWork::REASON_MALFORMED, $this->pow->verify(base64_encode('{"salt":1}'), '', 0, self::NOW));
    }

    public function testTamperedSignatureOrSaltIsRefused(): void
    {
        $challenge = $this->pow->issue(500, 0, self::NOW);
        $data = json_decode((string) base64_decode($this->solved($challenge), true), true);

        $badSignature = $data;
        $badSignature['signature'] = str_repeat('0', 64);
        self::assertSame(ProofOfWork::REASON_SIGNATURE, $this->pow->verify(base64_encode((string) json_encode($badSignature)), '', 0, self::NOW));

        // a forged salt (e.g. a later expiry) with a matching hash still fails the signature
        $forged = $data;
        $forged['salt'] = substr($data['salt'], 0, 25) . (self::NOW + 99999) . '.' . self::NOW;
        $forged['challenge'] = hash('sha256', $forged['salt'] . $data['number']);
        self::assertSame(ProofOfWork::REASON_SIGNATURE, $this->pow->verify(base64_encode((string) json_encode($forged)), '', 0, self::NOW));

        // signed by another secret
        $other = new ProofOfWork('another-secret', new ArrayAdapter());
        self::assertSame(ProofOfWork::REASON_SIGNATURE, $other->verify($this->solved($challenge), '', 0, self::NOW));
    }

    /** difficulty and minimum time are part of the signed salt: lowering them (or the number above the maximum) is refused */
    public function testDifficultyCannotBeDowngraded(): void
    {
        $data = json_decode((string) base64_decode($this->solved($this->pow->issue(5000, 3, self::NOW)), true), true);

        $lower = $data;
        $lower['salt'] = preg_replace('/\.5000\.3$/', '.100.3', $data['salt']);
        $lower['challenge'] = hash('sha256', $lower['salt'] . $data['number']);
        self::assertSame(ProofOfWork::REASON_SIGNATURE, $this->pow->verify(base64_encode((string) json_encode($lower)), '', 0, self::NOW + 9));

        $faster = $data;
        $faster['salt'] = preg_replace('/\.5000\.3$/', '.5000.0', $data['salt']);
        $faster['challenge'] = hash('sha256', $faster['salt'] . $data['number']);
        self::assertSame(ProofOfWork::REASON_SIGNATURE, $this->pow->verify(base64_encode((string) json_encode($faster)), '', 0, self::NOW + 9));

        // the signed minimum time applies even when the caller asks for less
        self::assertSame(ProofOfWork::REASON_TOO_FAST, $this->pow->verify(base64_encode((string) json_encode($data)), '', 0, self::NOW + 1));
    }

    public function testExpiredChallengeIsRefused(): void
    {
        $payload = $this->solved($this->pow->issue(500, 0, self::NOW));

        self::assertSame(ProofOfWork::REASON_EXPIRED, $this->pow->verify($payload, '', 0, self::NOW + ProofOfWork::LIFETIME + 1));
        self::assertNull($this->pow->verify($payload, '', 0, self::NOW + ProofOfWork::LIFETIME));
    }

    public function testMinimumFillTimeAndHoneypot(): void
    {
        $payload = $this->solved($this->pow->issue(500, 3, self::NOW));

        self::assertSame(ProofOfWork::REASON_TOO_FAST, $this->pow->verify($payload, '', 3, self::NOW + 2));
        self::assertSame(ProofOfWork::REASON_HONEYPOT, $this->pow->verify($payload, 'http://spam.example', 3, self::NOW + 4));
        // refusals do not use the challenge up
        self::assertNull($this->pow->verify($payload, '', 3, self::NOW + 4));
    }
}
