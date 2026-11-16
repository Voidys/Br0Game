<?php

declare(strict_types=1);

namespace App\Tests\Unit\Model;

use App\Game\Model\User;
use PHPUnit\Framework\TestCase;

/**
 * The typed User snapshot: field mapping and casts of a state array, tech level accessors with 0 defaults,
 * bonus factors, ban/vacation/inactivity flags.
 */
final class UserTest extends TestCase
{
    private const RESOURCE = [106 => 'spy_tech', 108 => 'computer_tech', 118 => 'hyperspace_motor_tech', 901 => 'metal'];

    public function testMapsAndCastsTheStateArray(): void
    {
        $user = User::fromState([
            'id' => '7',
            'username' => 'neo',
            'universe' => '1',
            'authlevel' => '2',
            'id_planet' => '70',
            'galaxy' => '3',
            'system' => '120',
            'planet' => '8',
            'onlinetime' => '1000',
            'ally_id' => '4',
            'urlaubs_modus' => '1',
            'banaday' => '5000',
            'rebuild_protection' => '900',
            'rebuild_protection_attackers' => null,
            'total_points' => '12345',
            'b_tech' => '777',
            'spy_tech' => '5',
            'computer_tech' => 3,
        ], self::RESOURCE);

        $this->assertSame(7, $user->id);
        $this->assertSame('neo', $user->username);
        $this->assertSame(1, $user->universe);
        $this->assertSame(2, $user->authLevel);
        $this->assertSame(70, $user->homePlanetId);
        $this->assertSame([3, 120, 8], [$user->galaxy, $user->system, $user->position]);
        $this->assertSame(4, $user->allianceId);
        $this->assertTrue($user->vacation);
        $this->assertSame(5000, $user->banUntil);
        $this->assertSame(900, $user->rebuildProtection);
        $this->assertSame('', $user->rebuildProtectionAttackers);
        $this->assertSame(12345, $user->totalPoints);
        $this->assertSame(777, $user->researchEnd);
    }

    public function testMissingKeysDefaultToZeroAndHasReportsPresence(): void
    {
        $user = User::fromState(['id' => 1, 'spy_tech' => 4, 'computer_tech' => null], self::RESOURCE);

        $this->assertSame(4, $user->level(106));
        $this->assertTrue($user->has(106));
        $this->assertSame(0, $user->level(108), 'NULL column reads as 0');
        $this->assertFalse($user->has(108));
        $this->assertSame(0, $user->level(118), 'absent column reads as 0');
        $this->assertFalse($user->has(118));
        $this->assertSame(0, $user->level(999), 'unknown element reads as 0');
        $this->assertSame(0, $user->universe);
        $this->assertFalse($user->vacation);
        $this->assertSame('', $user->username);
    }

    public function testFactorsAndFlags(): void
    {
        $user = User::fromState(['banaday' => 100, 'onlinetime' => 50, 'factor' => ['FlyTime' => '-0.1', 'Attack' => 0]], self::RESOURCE);

        $this->assertSame(-0.1, $user->factor('FlyTime'));
        $this->assertSame(0.0, $user->factor('Attack'));
        $this->assertNull($user->factor('Unknown'));
        $this->assertTrue($user->isBanned(99));
        $this->assertFalse($user->isBanned(100));
        $this->assertTrue($user->isInactive(1000, 500));
        $this->assertFalse($user->isInactive(500, 500));
    }

    public function testIsASnapshot(): void
    {
        $row = ['id' => 1, 'spy_tech' => 1];
        $user = User::fromState($row, self::RESOURCE);
        $row['spy_tech'] = 9;

        $this->assertSame(1, $user->level(106));
    }
}
