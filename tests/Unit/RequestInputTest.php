<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\RequestInput;
use App\Game\Core\Singleton;
use App\Routing\GameRoute;
use App\Runtime\RuntimeContext;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The typed accessors of RequestInput must return exactly what typed() (the former HTTP::_GP()) returns
 * for the matching default (same casts, same HTML encoding), so controllers can switch without changing
 * what the templates see. Also pins the route naming of GameRoute.
 */
final class RequestInputTest extends TestCase
{
    /**
     * @param array<string, mixed> $input
     */
    private function input(array $input): RequestInput
    {
        $state = new Singleton(new RuntimeContext(dirname(__DIR__, 2)));
        $state->requestInput = $input;

        return new RequestInput($state);
    }

    /** @return iterable<string, array{mixed}> */
    public static function values(): iterable
    {
        yield 'plain' => ['hello'];
        yield 'number' => ['42'];
        yield 'negative' => ['-17'];
        yield 'float' => ['3.75'];
        yield 'exponent' => ['1e3'];
        yield 'numeric junk' => ['12abc'];
        yield 'empty' => [''];
        yield 'spaces' => ['  padded  '];
        yield 'nul byte' => ["a\0b"];
        yield 'crlf' => ["line1\r\nline2\rline3"];
        yield 'html' => ['<b>"x" & \'y\'</b>'];
        yield 'already encoded' => ['&amp; &lt;'];
        yield 'multibyte' => ['Zoë ünï 日本'];
        yield 'invalid utf8' => ["bad\xC3\x28"];
        yield 'array where scalar expected' => [['a', 'b']];
        yield 'empty array' => [[]];
        yield 'nested array' => [['a' => ['b']]];
        yield 'overflow' => ['99999999999999999999'];
    }

    #[DataProvider('values')]
    public function testScalarAccessorsMatchGP(mixed $value): void
    {
        $input = $this->input(['x' => $value]);

        $this->assertSame($input->typed('x', 0), $input->int('x'));
        $this->assertSame($input->typed('x', 0.0), $input->float('x'));
        $this->assertSame($input->typed('x', 0, false, true), $input->bigNumber('x'));
        $this->assertSame($input->typed('x', ''), $input->string('x'));
        $this->assertSame($input->typed('x', '', true), $input->string('x', '', true));
        $this->assertSame($input->rawString('x'), $input->rawString('x'));
    }

    #[DataProvider('values')]
    public function testListAccessorsMatchGPForArrays(mixed $value): void
    {
        $input = $this->input(['x' => [$value, 5, '7x']]);

        $flat = array_values(array_filter($input->typed('x', [0]), static fn(mixed $v): bool => !is_array($v)));
        $this->assertSame(array_map('intval', $flat), $input->intList('x'));
        $this->assertSame(
            array_map('strval', array_values(array_filter($input->typed('x', ['']), static fn(mixed $v): bool => !is_array($v)))),
            $input->stringList('x'),
        );
    }

    public function testMissingInputGivesTheDefault(): void
    {
        $input = $this->input([]);

        $this->assertFalse($input->has('x'));
        $this->assertSame(0, $input->int('x'));
        $this->assertSame(9, $input->int('x', 9));
        $this->assertSame(0.0, $input->float('x'));
        $this->assertSame('', $input->string('x'));
        $this->assertSame('dflt', $input->string('x', 'dflt'));
        $this->assertSame('', $input->rawString('x'));
        $this->assertFalse($input->bool('x'));
        $this->assertTrue($input->bool('x', true));
        $this->assertSame([], $input->intList('x'));
        $this->assertSame([], $input->stringList('x'));
    }

    public function testScalarWhereAnArrayIsExpectedGivesAnEmptyList(): void
    {
        $input = $this->input(['x' => '5']);

        $this->assertSame([], $input->intList('x'));
        $this->assertSame([], $input->stringList('x'));
    }

    public function testListsAreCastAndReIndexed(): void
    {
        $input = $this->input(['x' => ['a' => '3', 'b' => '-4', 'c' => 'z', 'd' => ['no']]]);

        $this->assertSame([3, -4, 0], $input->intList('x'));
    }

    public function testStringKeepsTheGameEncodingAndPasswordsStayRaw(): void
    {
        $input = $this->input(['x' => " <a href=\"1\">T&C</a>\r\n ", 'pw' => ' p&w"<>']);

        $this->assertSame('&lt;a href=&quot;1&quot;&gt;T&amp;C&lt;/a&gt;', $input->string('x'));
        $this->assertSame(' p&w"<>', $input->rawString('pw'));
    }

    /** @return iterable<string, array{mixed, bool}> */
    public static function flags(): iterable
    {
        yield '1' => ['1', true];
        yield 'on' => ['on', true];
        yield 'TRUE' => ['TRUE', true];
        yield 'yes' => [' yes ', true];
        yield '0' => ['0', false];
        yield 'off' => ['off', false];
        yield 'empty' => ['', false];
        yield 'array' => [['1'], false];
        yield 'other' => ['2', false];
    }

    #[DataProvider('flags')]
    public function testBoolReadsCheckboxStyleFlags(mixed $value, bool $expected): void
    {
        $input = $this->input(['x' => $value]);

        $this->assertSame($expected, $input->bool('x', !$expected));
    }

    public function testGameRouteNamesAndDefaults(): void
    {
        $route = new GameRoute('index', 'lost-password/reset', 'lostPassword', 'newPassword', ['GET', 'POST']);

        $this->assertSame('login_lost_password_new_password', $route->name);
        $this->assertSame('/{uni}lost-password/reset', $route->path);
        $this->assertSame(['GET', 'POST'], $route->methods);
        $this->assertSame(['uni' => '', '_area' => 'index', '_page' => 'lostpassword', '_mode' => 'newpassword'], $route->defaults);

        $this->assertSame('account_board_post', (new GameRoute('account', 'account/board/{id}', 'board', 'post'))->name);
    }
}
