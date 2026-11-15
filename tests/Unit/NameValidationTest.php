<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins PlayerUtil::isNameValid(): the allow-list for player names (ASCII letters, digits, German umlauts and ß,
 * space, underscore, dot, hyphen) and for planet names (any Unicode letter or number, plus space _ - .).
 *
 * Why: names end up in HTML, mails, fleet messages and URLs; the validation is the first line of defence against
 * markup and control characters in them. The regexes (including the empty string being valid) must only change on purpose.
 */
final class NameValidationTest extends TestCase
{
    private function players(): PlayerUtil
    {
        return new PlayerUtil(
            $this->createStub(Database::class),
            $this->createStub(ConfigRegistry::class),
            new Singleton(new RuntimeContext(dirname(__DIR__, 2))),
        );
    }

    /** @return iterable<string, array{string, bool}> */
    public static function playerNames(): iterable
    {
        yield 'plain' => ['Commander', true];
        yield 'digits and separators' => ['Cmdr_42-x.y z', true];
        yield 'german umlauts and sharp s' => ['Müller Größe ÄÖÜ', true];
        yield 'empty string passes the pattern' => ['', true];
        yield 'markup' => ['<b>x</b>', false];
        yield 'quote' => ["o'brien", false];
        yield 'double quote' => ['a"b', false];
        yield 'ampersand' => ['a&b', false];
        yield 'semicolon' => ['a;b', false];
        yield 'slash' => ['a/b', false];
        yield 'backslash' => ['a\\b', false];
        yield 'at sign' => ['a@b', false];
        yield 'newline' => ["a\nb", false];
        yield 'NUL byte' => ["a\0b", false];
        yield 'tab' => ["a\tb", false];
        yield 'accented letter outside the list' => ['José', false];
        yield 'cyrillic' => ['Игрок', false];
        yield 'emoji' => ['x😀', false];
        yield 'percent' => ['100%', false];
    }

    #[DataProvider('playerNames')]
    public function testPlayerName(string $name, bool $valid): void
    {
        $this->assertSame($valid, $this->players()->isNameValid($name));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function planetNames(): iterable
    {
        yield 'plain' => ['Homeworld', true];
        yield 'with number' => ['Colony 7', true];
        yield 'separators' => ['New_Earth-2.0', true];
        yield 'umlauts' => ['Köln', true];
        yield 'any unicode letter' => ['Тера Nova 地球', true];
        yield 'accents' => ['José', true];
        yield 'empty string passes the pattern' => ['', true];
        yield 'markup' => ['<i>x</i>', false];
        yield 'quote' => ["it's", false];
        yield 'double quote' => ['a"b', false];
        yield 'ampersand' => ['a&b', false];
        yield 'newline' => ["a\nb", false];
        yield 'NUL byte' => ["a\0b", false];
        yield 'emoji' => ['Planet 😀', false];
        yield 'semicolon' => ['a;b', false];
        yield 'invalid utf-8' => ["a\xC3\x28", false];
    }

    #[DataProvider('planetNames')]
    public function testPlanetName(string $name, bool $valid): void
    {
        $this->assertSame($valid, $this->players()->isNameValid($name, true));
    }
}
