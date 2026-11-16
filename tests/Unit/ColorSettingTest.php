<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Settings\ColorSetting;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ColorSettingTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function validColors(): iterable
    {
        yield 'short' => ['#fff'];
        yield 'long' => ['#66CC33'];
        yield 'alpha' => ['#66cc3380'];
    }

    /** @return iterable<string, array{string}> */
    public static function invalidColors(): iterable
    {
        yield 'named' => ['red'];
        yield 'injection' => ['#fff;} body{display:none'];
        yield 'url' => ['url(http://evil)'];
        yield 'five digits' => ['#12345'];
        yield 'trailing newline' => ["#fff\n"];
        yield 'empty' => [''];
    }

    #[DataProvider('validColors')]
    public function testValidColorIsKept(string $color): void
    {
        self::assertSame($color, ColorSetting::sanitize($color, '#000000'));
    }

    #[DataProvider('invalidColors')]
    public function testInvalidColorFallsBackToDefault(string $color): void
    {
        self::assertSame('#123456', ColorSetting::sanitize($color, '#123456'));
    }

    public function testNonStringFallsBack(): void
    {
        self::assertSame('#123456', ColorSetting::sanitize(null, '#123456'));
        self::assertSame('#123456', ColorSetting::sanitize(['#fff'], '#123456'));
    }
}
