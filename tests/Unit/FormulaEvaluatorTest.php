<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use App\Game\Core\FormulaEvaluator;
use App\Game\Core\FormulaException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The safe formula evaluator that replaced eval() for the production/storage formulas: operators, precedence,
 * associativity, functions, the legacy quirks of the stored formulas (trailing ";", unary minus on -0.0) and
 * rejection of everything that is not whitelisted. Equality with the former eval() results on the real formulas
 * is pinned by ResourceProductionTest.
 */
final class FormulaEvaluatorTest extends TestCase
{
    /** @return iterable<string, array{string, mixed}> */
    public static function expressions(): iterable
    {
        yield 'integer literal stays int' => ['10', 10];
        yield 'float literal' => ['1.1', 1.1];
        yield 'leading dot float' => ['.5', 0.5];
        yield 'exponent float' => ['1e3', 1000.0];
        yield 'precedence * over +' => ['2 + 3 * 4', 14];
        yield 'parentheses' => ['(2 + 3) * 4', 20];
        yield 'minus is left associative' => ['10 - 3 - 2', 5];
        yield 'division is left associative' => ['100 / 10 / 5', 2];
        yield 'modulo' => ['17 % 5', 2];
        yield 'unary minus' => ['-3 + 5', 2];
        yield 'unary minus on parentheses' => ['-(2 + 3)', -5];
        yield 'double unary' => ['- -3', 3];
        yield 'unary plus' => ['+4', 4];
        yield 'binary minus without space' => ['1.44 -0.004 * 10', 1.4];
        yield 'power operator' => ['2 ** 3 ** 2', 512];
        yield 'power binds tighter than unary minus' => ['-2 ** 2', -4];
        yield 'pow' => ['pow(2, 10)', 1024];
        yield 'pow float' => ['pow((1.1), 3)', 1.1 ** 3];
        yield 'floor' => ['floor(2.7)', 2.0];
        yield 'ceil' => ['ceil(2.1)', 3.0];
        yield 'round' => ['round(2.5)', 3.0];
        yield 'round digits' => ['round(3.14159, 2)', 3.14];
        yield 'sqrt' => ['sqrt(16)', 4.0];
        yield 'abs' => ['abs(-7)', 7];
        yield 'min' => ['min(3, 1, 2)', 1];
        yield 'max' => ['max(3, 1, 2)', 3];
        yield 'function names are case insensitive' => ['FLOOR(2.5)', 2.0];
        yield 'nested calls' => ['floor(2.5 * pow(1.8331954764, 4)) * 5000', floor(2.5 * 1.8331954764 ** 4) * 5000];
        yield 'trailing semicolon' => ['2 * 3;', 6];
        yield 'legacy this prefix' => ['$this->BuildLevel * 2', 14];
        yield 'variables' => ['$BuildLevel + $BuildTemp + $BuildEnergy + $BuildLevelFactor', 7 + 50 + 3 + 10];
        yield 'real production formula' => ['(30 * $BuildLevel * pow((1.1), $BuildLevel)) * (0.1 * $BuildLevelFactor)', (30 * 7 * 1.1 ** 7) * (0.1 * 10)];
    }

    #[DataProvider('expressions')]
    public function testEvaluates(string $formula, mixed $expected): void
    {
        $variables = ['BuildLevel' => 7, 'BuildTemp' => 50, 'BuildEnergy' => 3, 'BuildLevelFactor' => 10];

        $result = FormulaEvaluator::evaluate($formula, $variables);

        $this->assertEqualsWithDelta($expected, $result, 1e-12);
        $this->assertSame(is_int($expected), is_int($result), 'int/float typing like PHP');
    }

    public function testNegatedZeroKeepsItsSignLikePhp(): void
    {
        $this->assertSame('-0', (string) FormulaEvaluator::evaluate('-(10 * $BuildLevel * pow(1.1, $BuildLevel))', ['BuildLevel' => 0]));
        $this->assertSame(0, FormulaEvaluator::evaluate('-$BuildLevel', ['BuildLevel' => 0]));
    }

    public function testEquivalentToNativePhpArithmeticBitForBit(): void
    {
        $level = 13;
        $factor = 6;
        $temp = -33;
        $energy = 9;

        $this->assertSame(
            floor(10 * $level * pow(1.1, $level) * (1.44 - 0.004 * $temp)) * (0.1 * $factor),
            FormulaEvaluator::evaluate('(floor(10 * $BuildLevel * pow(1.1, $BuildLevel) * (1.44 -0.004 * $BuildTemp)) * (0.1 * $BuildLevelFactor))', ['BuildLevel' => $level, 'BuildTemp' => $temp, 'BuildLevelFactor' => $factor]),
        );
        $this->assertSame(
            30 * $level * pow(1.05 + ($energy * 0.01), $level) * (0.1 * $factor),
            FormulaEvaluator::evaluate('30 * $BuildLevel * pow(1.05 + ($BuildEnergy * 0.01), $BuildLevel) * (0.1 * $BuildLevelFactor)', ['BuildLevel' => $level, 'BuildEnergy' => $energy, 'BuildLevelFactor' => $factor]),
        );
    }

    public function testMissingVariableReadsAsNullLikeAnUnsetPhpVariable(): void
    {
        $this->assertSame(0, FormulaEvaluator::evaluate('$BuildLevel * 5', []));
    }

    public function testDivisionByZeroThrowsLikePhp(): void
    {
        $this->expectException(\DivisionByZeroError::class);
        FormulaEvaluator::evaluate('1 / $BuildLevel', ['BuildLevel' => 0]);
    }

    /** @return iterable<string, array{string}> */
    public static function rejected(): iterable
    {
        yield 'unknown variable' => ['$USER'];
        yield 'array lookup' => ['$PLANET[\'metal\']'];
        yield 'unknown function' => ['system(1)'];
        yield 'function exec' => ['exec(\'ls\')'];
        yield 'php open tag' => ['<?php echo 1; ?>'];
        yield 'backtick' => ['`ls`'];
        yield 'string literal' => ['"abc"'];
        yield 'constant' => ['PHP_INT_MAX'];
        yield 'variable function' => ['$BuildLevel(1)'];
        yield 'method call' => ['$this->foo()'];
        yield 'comparison' => ['1 < 2'];
        yield 'ternary' => ['1 ? 2 : 3'];
        yield 'assignment' => ['$BuildLevel = 5'];
        yield 'unbalanced' => ['(1 + 2'];
        yield 'trailing operator' => ['1 +'];
        yield 'two statements' => ['1; 2'];
        yield 'empty' => [''];
        yield 'wrong argument count' => ['pow(2)'];
        yield 'semicolon first' => [';'];
    }

    #[DataProvider('rejected')]
    public function testRejects(string $formula): void
    {
        $this->expectException(FormulaException::class);
        FormulaEvaluator::evaluate($formula, ['BuildLevel' => 1]);
    }

    public function testNoCodeIsExecuted(): void
    {
        $marker = sys_get_temp_dir() . '/formula-evaluator-' . bin2hex(random_bytes(4));

        try {
            FormulaEvaluator::evaluate('file_put_contents(\'' . $marker . '\', 1)', []);
            $this->fail('expected FormulaException');
        } catch (FormulaException) {
        }

        $this->assertFileDoesNotExist($marker);
    }

    public function testCompileGivesAReusableClosure(): void
    {
        $fn = FormulaEvaluator::compile('$BuildLevel * 2');

        $this->assertSame(6, $fn(['BuildLevel' => 3]));
        $this->assertSame(8, $fn(['BuildLevel' => 4]));
    }
}
