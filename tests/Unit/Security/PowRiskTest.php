<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\PowRisk;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

/** Tier selection of the adaptive bot check: thresholds per IP and per prefix, windows, sensitivity. */
final class PowRiskTest extends TestCase
{
    private const NOW = 1_800_000_000;

    private PowRisk $risk;

    protected function setUp(): void
    {
        $this->risk = new PowRisk(new ArrayAdapter());
    }

    private function record(string $kind, string $ip, int $times, int $at = self::NOW): void
    {
        for ($i = 0; $i < $times; $i++) {
            $this->risk->record($kind, $ip, $at);
        }
    }

    public function testUnknownClientIsNormal(): void
    {
        self::assertSame(PowRisk::TIER_NORMAL, $this->risk->tier('203.0.113.7', self::NOW));
    }

    public function testIpThresholdsPerSignal(): void
    {
        $this->record(PowRisk::KIND_CHALLENGE, '203.0.113.1', 4);
        self::assertSame(PowRisk::TIER_NORMAL, $this->risk->tier('203.0.113.1', self::NOW));
        $this->record(PowRisk::KIND_CHALLENGE, '203.0.113.1', 1);
        self::assertSame(PowRisk::TIER_ELEVATED, $this->risk->tier('203.0.113.1', self::NOW));

        $this->record(PowRisk::KIND_FAILURE, '203.0.113.2', 3);
        self::assertSame(PowRisk::TIER_ELEVATED, $this->risk->tier('203.0.113.2', self::NOW));
        $this->record(PowRisk::KIND_FAILURE, '203.0.113.2', 7);
        self::assertSame(PowRisk::TIER_HIGH, $this->risk->tier('203.0.113.2', self::NOW));

        $this->record(PowRisk::KIND_SUCCESS, '203.0.113.3', 2);
        self::assertSame(PowRisk::TIER_ELEVATED, $this->risk->tier('203.0.113.3', self::NOW));
        $this->record(PowRisk::KIND_SUCCESS, '203.0.113.3', 3);
        self::assertSame(PowRisk::TIER_HIGH, $this->risk->tier('203.0.113.3', self::NOW));
    }

    public function testPrefixNeedsThreeTimesAsManyEvents(): void
    {
        // 8 failures spread over four addresses (2 each, below the IP threshold): prefix threshold is 9
        foreach (['198.51.100.1', '198.51.100.2', '198.51.100.3', '198.51.100.4'] as $ip) {
            $this->record(PowRisk::KIND_FAILURE, $ip, 2);
        }
        self::assertSame(PowRisk::TIER_NORMAL, $this->risk->tier('198.51.100.99', self::NOW));
        $this->record(PowRisk::KIND_FAILURE, '198.51.100.5', 1);
        self::assertSame(PowRisk::TIER_ELEVATED, $this->risk->tier('198.51.100.99', self::NOW));
        // a different /24 stays normal
        self::assertSame(PowRisk::TIER_NORMAL, $this->risk->tier('198.51.101.99', self::NOW));
    }

    public function testEventsExpireWithTheirWindow(): void
    {
        $this->record(PowRisk::KIND_CHALLENGE, '203.0.113.9', 5);
        self::assertSame(PowRisk::TIER_ELEVATED, $this->risk->tier('203.0.113.9', self::NOW + 599));
        self::assertSame(PowRisk::TIER_NORMAL, $this->risk->tier('203.0.113.9', self::NOW + 601));
    }

    public function testSensitivityScalesThresholds(): void
    {
        $this->record(PowRisk::KIND_FAILURE, '203.0.113.4', 3);
        self::assertSame(PowRisk::TIER_NORMAL, $this->risk->tier('203.0.113.4', self::NOW, 200));
        self::assertSame(PowRisk::TIER_ELEVATED, $this->risk->tier('203.0.113.4', self::NOW, 100));
        self::assertSame(PowRisk::TIER_HIGH, $this->risk->tier('203.0.113.4', self::NOW, 30));
    }

    public function testIpv6PrefixIsSlash64(): void
    {
        [, $a] = $this->risk->subjects('2001:db8:1:2:aaaa::1');
        [, $b] = $this->risk->subjects('2001:db8:1:2:bbbb::9');
        [, $c] = $this->risk->subjects('2001:db8:1:3::1');

        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }
}
