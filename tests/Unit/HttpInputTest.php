<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\HTTP;
use App\Game\Core\Singleton;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins HTTP::_GP(), the single entry point for request input in the game: the type of the default decides the
 * cast (int, float, string, array), strings are HTML-encoded, CR/LF-normalised, stripped of NUL bytes, trimmed and (unless
 * multibyte is allowed) reduced to ASCII; a missing key or an unsupported type gives the default back.
 *
 * Why: this is the input-sanitising layer the whole game relies on ("_GP already encodes input", the Twig escaping
 * strategy `game_html` depends on it). Security hardening must not loosen it, and refactors must not double-encode.
 * No database or kernel: HTTP only needs the game state object.
 */
final class HttpInputTest extends TestCase
{
    /** @param array<string, mixed> $input */
    private function http(array $input): HTTP
    {
        $state = new Singleton(new RuntimeContext(dirname(__DIR__, 2)));
        $state->requestInput = $input;

        return new HTTP($state);
    }

    public function testRawInputKeepsTheValueExactlyAsSent(): void
    {
        $http = $this->http(['pw' => "  a&b\"<c>'ä ", 'arr' => ['x']]);

        $this->assertSame("  a&b\"<c>'ä ", $http->rawInput('pw'));
        $this->assertSame('', $http->rawInput('arr'));
        $this->assertSame('', $http->rawInput('missing'));
    }

    public function testMissingKeyReturnsTheDefault(): void
    {
        $http = $this->http([]);

        $this->assertSame(7, $http->_GP('x', 7));
        $this->assertSame('dflt', $http->_GP('x', 'dflt'));
        $this->assertSame(1.5, $http->_GP('x', 1.5));
        $this->assertSame([], $http->_GP('x', []));
        $this->assertNull($http->_GP('x', null));
    }

    /** @return iterable<string, array{mixed, int}> */
    public static function integerCases(): iterable
    {
        yield 'plain' => ['12', 12];
        yield 'negative' => ['-5', -5];
        yield 'trailing junk' => ['12abc', 12];
        yield 'leading junk' => ['abc12', 0];
        yield 'float string truncates' => ['12.9', 12];
        yield 'exponent' => ['1e3', 1000];
        yield 'empty string' => ['', 0];
        yield 'array input is 1 or 0' => [['x'], 1];
        yield 'overflow clamps' => ['99999999999999999999', PHP_INT_MAX];
    }

    #[DataProvider('integerCases')]
    public function testIntegerDefaultCastsToInt(mixed $input, int $expected): void
    {
        $this->assertSame($expected, $this->http(['x' => $input])->_GP('x', 0));
    }

    /** @return iterable<string, array{mixed, float}> */
    public static function floatCases(): iterable
    {
        yield 'plain' => ['12.5', 12.5];
        yield 'integer string' => ['3', 3.0];
        yield 'trailing junk' => ['1.5abc', 1.5];
        yield 'text' => ['abc', 0.0];
        yield 'exponent' => ['2e2', 200.0];
    }

    #[DataProvider('floatCases')]
    public function testFloatDefaultCastsToFloat(mixed $input, float $expected): void
    {
        $this->assertSame($expected, $this->http(['x' => $input])->_GP('x', 0.0));
    }

    public function testHighNumberFlagCastsToFloatEvenWithAnIntegerDefault(): void
    {
        $this->assertSame(99999999999999999999.0, $this->http(['x' => '99999999999999999999'])->_GP('x', 0, false, true));
        $this->assertSame(5.0, $this->http(['x' => '5'])->_GP('x', '', false, true));
    }

    /** @return iterable<string, array{string, string}> */
    public static function stringCases(): iterable
    {
        yield 'plain' => ['hello', 'hello'];
        yield 'trimmed' => ["  padded \t", 'padded'];
        yield 'html is encoded' => ['<script>alert("x")</script>', '&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt;'];
        yield 'single quote is encoded' => ["it's", 'it&#039;s'];
        yield 'ampersand is encoded' => ['a&b', 'a&amp;b'];
        yield 'CRLF becomes LF' => ["a\r\nb\rc", "a\nb\nc"];
        yield 'NUL bytes are removed' => ["a\0b", 'ab'];
        yield 'umlaut is reduced to ?' => ['Müller', 'M??ller'];
        yield 'emoji is reduced to ?' => ['hi 😀', 'hi ????'];
        yield 'sql-ish stays text' => ["' OR 1=1 --", '&#039; OR 1=1 --'];
    }

    #[DataProvider('stringCases')]
    public function testStringDefaultIsEncodedAndReducedToAscii(string $input, string $expected): void
    {
        $this->assertSame($expected, $this->http(['x' => $input])->_GP('x', ''));
    }

    public function testMultibyteKeepsValidUtf8AndDropsInvalidSequences(): void
    {
        $this->assertSame('Müller 😀', $this->http(['x' => 'Müller 😀'])->_GP('x', '', true));
        $this->assertSame('', $this->http(['x' => "bad \xC3\x28 utf8"])->_GP('x', '', true));
        // markup is encoded in multibyte mode as well
        $this->assertSame('&lt;b&gt;ä', $this->http(['x' => '<b>ä'])->_GP('x', '', true));
    }

    public function testArrayDefaultQuotesEveryElementRecursively(): void
    {
        $http = $this->http(['x' => ['<a>', ['<b>', 'ok'], 'ü']]);

        $this->assertSame(['&lt;a&gt;', ['&lt;b&gt;', 'ok'], '??'], $http->_GP('x', []));
    }

    public function testArrayDefaultStartingWithZeroForcesIntegers(): void
    {
        $http = $this->http(['x' => ['1', '2abc', 'x', '3.7']]);

        $this->assertSame([1, 2, 0, 3], $http->_GP('x', [0]));
    }

    public function testArrayDefaultIgnoresScalarInput(): void
    {
        $this->assertSame(['d'], $this->http(['x' => 'scalar'])->_GP('x', ['d']));
    }

    public function testUnsupportedDefaultTypesReturnTheDefaultUntouched(): void
    {
        $http = $this->http(['x' => '1']);

        $this->assertTrue($http->_GP('x', true));
        $this->assertNull($http->_GP('x', null));
    }

    public function testArrayInputForAStringDefaultGivesTheDefault(): void
    {
        // ?name[]=x for a string parameter counts as missing (it used to raise a TypeError)
        $this->assertSame('d', $this->http(['x' => ['a']])->_GP('x', 'd'));
    }

    public function testNumericZeroIsNotTreatedAsMissing(): void
    {
        // isset(), not empty(): "0" is a value
        $this->assertSame(0, $this->http(['x' => '0'])->_GP('x', 5));
        $this->assertSame('0', $this->http(['x' => '0'])->_GP('x', 'd'));
    }

    public function testNullInputCountsAsMissing(): void
    {
        $this->assertSame(5, $this->http(['x' => null])->_GP('x', 5));
    }
}
