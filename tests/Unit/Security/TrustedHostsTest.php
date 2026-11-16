<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\TrustedHosts;
use PHPUnit\Framework\TestCase;

final class TrustedHostsTest extends TestCase
{
    public function testEmptyMeansNoRestriction(): void
    {
        $this->assertSame([], TrustedHosts::patterns(''));
        $this->assertSame([], TrustedHosts::patterns(' , '));
    }

    public function testExactAndWildcardHosts(): void
    {
        $patterns = TrustedHosts::patterns('Example.com, *.game.example.org');
        $matches = static fn(string $host): bool => array_filter($patterns, static fn(string $p): bool => preg_match('{' . $p . '}i', $host) === 1) !== [];

        $this->assertTrue($matches('example.com'));
        $this->assertFalse($matches('evil.example.com'));
        $this->assertFalse($matches('example.com.evil.test'));
        $this->assertTrue($matches('game.example.org'));
        $this->assertTrue($matches('uni2.game.example.org'));
        $this->assertFalse($matches('xgame.example.org'));
    }
}
