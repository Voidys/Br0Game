<?php

// no strict_types on purpose: native functions must coerce null/numeric strings exactly like the former eval() code did

namespace App\Game\Core;

/**
 * Safe evaluator for the production and storage formulas of the vars table (columns production901..911,
 * storage901..903), replacing eval() of PHP code.
 *
 * Grammar (whitespace is ignored, a trailing ";" and the legacy "$this->" prefix are tolerated):
 *
 *   formula    := expression ";"*
 *   expression := term (("+" | "-") term)*              left associative
 *   term       := unary (("*" | "/" | "%") unary)*      left associative
 *   unary      := ("-" | "+") unary | power
 *   power      := primary ["**" unary]                  binds tighter than unary minus, like PHP
 *   primary    := number | "$" name | name "(" [expression ("," expression)*] ")" | "(" expression ")"
 *
 * Numbers are PHP literals (integers stay int, "1.1" / ".5" / "1e3" are float). Variables and functions are
 * whitelisted (see ALLOWED_VARIABLES / FUNCTIONS); anything else throws FormulaException when the formula is
 * compiled, so a formula can never run code. Operators and functions are the native PHP ones, applied in the
 * same order as the PHP parser would, so results are identical to the former eval() down to the last float bit
 * (including -0.0 and int/float typing).
 *
 * A formula is compiled once per process into a closure tree (see compile()) and evaluated with
 * evaluate(): `evaluate('30 * $BuildLevel * pow(1.1, $BuildLevel)', ['BuildLevel' => 3])`.
 */
final class FormulaEvaluator
{
    /** variables a formula may read; a variable that is not passed to evaluate() reads as null, like an unset PHP variable */
    public const ALLOWED_VARIABLES = ['BuildLevel', 'BuildTemp', 'BuildEnergy', 'BuildLevelFactor'];

    /** native PHP functions a formula may call (lowercase), mapped to their allowed argument counts [min, max] */
    private const FUNCTIONS = [
        'abs' => [1, 1],
        'ceil' => [1, 1],
        'floor' => [1, 1],
        'round' => [1, 2],
        'sqrt' => [1, 1],
        'pow' => [2, 2],
        'min' => [1, 8],
        'max' => [1, 8],
        'log' => [1, 2],
        'exp' => [1, 1],
    ];

    /** @var array<string, \Closure(array<string, mixed>): mixed> compiled formulas by text */
    private static array $compiled = [];

    /**
     * Compiles (once per process) and evaluates a formula.
     *
     * @param array<string, mixed> $variables values for ALLOWED_VARIABLES, e.g. ['BuildLevel' => 5]
     * @throws FormulaException when the formula is invalid or uses anything not whitelisted
     * @throws \DivisionByZeroError for "/" or "%" by zero, as PHP does
     */
    public static function evaluate(string $formula, array $variables): mixed
    {
        return (self::$compiled[$formula] ??= self::compile($formula))($variables);
    }

    /**
     * Parses a formula into a closure that takes the variable array and returns the result.
     *
     * @return \Closure(array<string, mixed>): mixed
     * @throws FormulaException
     */
    public static function compile(string $formula): \Closure
    {
        $tokens = self::tokenize(str_replace('this->', '', $formula));
        $position = 0;
        $node = self::parseExpression($tokens, $position);

        while (($tokens[$position]['t'] ?? null) === ';') {
            ++$position;
        }
        if (isset($tokens[$position])) {
            throw new FormulaException('Unexpected "' . $tokens[$position]['v'] . '" in formula: ' . $formula);
        }

        return $node;
    }

    /**
     * Splits the formula into tokens: number (n), variable (v), identifier (f), operator/parenthesis/comma/semicolon.
     *
     * @return list<array{t: string, v: string}>
     */
    private static function tokenize(string $formula): array
    {
        $tokens = [];
        $length = strlen($formula);
        $i = 0;

        while ($i < $length) {
            $char = $formula[$i];

            if (ctype_space($char)) {
                ++$i;
            } elseif (preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/', $formula, $m, 0, $i) === 1) {
                $tokens[] = ['t' => 'n', 'v' => $m[0]];
                $i += strlen($m[0]);
            } elseif ($char === '$') {
                if (preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)/', $formula, $m, 0, $i) !== 1) {
                    throw new FormulaException('Invalid variable in formula: ' . $formula);
                }
                $tokens[] = ['t' => 'v', 'v' => $m[1]];
                $i += strlen($m[0]);
            } elseif (preg_match('/\G[A-Za-z_][A-Za-z0-9_]*/', $formula, $m, 0, $i) === 1) {
                $tokens[] = ['t' => 'f', 'v' => $m[0]];
                $i += strlen($m[0]);
            } elseif (str_starts_with(substr($formula, $i, 2), '**')) {
                $tokens[] = ['t' => '**', 'v' => '**'];
                $i += 2;
            } elseif (str_contains('+-*/%(),;', $char)) {
                $tokens[] = ['t' => $char, 'v' => $char];
                ++$i;
            } else {
                throw new FormulaException('Unsupported character "' . $char . '" in formula: ' . $formula);
            }
        }

        return $tokens;
    }

    /**
     * expression := term (("+" | "-") term)*
     *
     * @param list<array{t: string, v: string}> $tokens
     */
    private static function parseExpression(array $tokens, int &$p): \Closure
    {
        $left = self::parseTerm($tokens, $p);

        while (in_array($tokens[$p]['t'] ?? null, ['+', '-'], true)) {
            $operator = $tokens[$p++]['t'];
            $right = self::parseTerm($tokens, $p);
            $left = $operator === '+'
                ? static fn(array $v): mixed => $left($v) + $right($v)
                : static fn(array $v): mixed => $left($v) - $right($v);
        }

        return $left;
    }

    /**
     * term := unary (("*" | "/" | "%") unary)*
     *
     * @param list<array{t: string, v: string}> $tokens
     */
    private static function parseTerm(array $tokens, int &$p): \Closure
    {
        $left = self::parseUnary($tokens, $p);

        while (in_array($tokens[$p]['t'] ?? null, ['*', '/', '%'], true)) {
            $operator = $tokens[$p++]['t'];
            $right = self::parseUnary($tokens, $p);
            $left = match ($operator) {
                '*' => static fn(array $v): mixed => $left($v) * $right($v),
                '/' => static fn(array $v): mixed => $left($v) / $right($v),
                '%' => static fn(array $v): mixed => $left($v) % $right($v),
            };
        }

        return $left;
    }

    /**
     * unary := ("-" | "+") unary | power
     *
     * @param list<array{t: string, v: string}> $tokens
     */
    private static function parseUnary(array $tokens, int &$p): \Closure
    {
        $type = $tokens[$p]['t'] ?? null;
        if ($type === '-' || $type === '+') {
            ++$p;
            $operand = self::parseUnary($tokens, $p);

            return $type === '-'
                ? static fn(array $v): mixed => -$operand($v)
                : static fn(array $v): mixed => +$operand($v);
        }

        $base = self::parsePrimary($tokens, $p);
        if (($tokens[$p]['t'] ?? null) === '**') {
            ++$p;
            $exponent = self::parseUnary($tokens, $p);

            return static fn(array $v): mixed => $base($v) ** $exponent($v);
        }

        return $base;
    }

    /**
     * primary := number | "$" name | name "(" args ")" | "(" expression ")"
     *
     * @param list<array{t: string, v: string}> $tokens
     */
    private static function parsePrimary(array $tokens, int &$p): \Closure
    {
        $token = $tokens[$p] ?? null;
        if ($token === null) {
            throw new FormulaException('Unexpected end of formula');
        }
        ++$p;

        switch ($token['t']) {
            case 'n':
                // "10" -> int, "1.1" / "1e3" -> float, an int literal too large for int -> float, as PHP does
                $value = $token['v'] + 0;

                return static fn(array $v): mixed => $value;

            case 'v':
                $name = $token['v'];
                if (!in_array($name, self::ALLOWED_VARIABLES, true)) {
                    throw new FormulaException('Variable $' . $name . ' is not allowed in a formula');
                }

                return static fn(array $v): mixed => $v[$name] ?? null;

            case '(':
                $inner = self::parseExpression($tokens, $p);
                self::expect($tokens, $p, ')');

                return $inner;

            case 'f':
                $function = strtolower($token['v']);
                if (!isset(self::FUNCTIONS[$function])) {
                    throw new FormulaException('Function ' . $token['v'] . '() is not allowed in a formula');
                }
                self::expect($tokens, $p, '(');
                $arguments = [];
                if (($tokens[$p]['t'] ?? null) !== ')') {
                    do {
                        $arguments[] = self::parseExpression($tokens, $p);
                    } while (($tokens[$p]['t'] ?? null) === ',' && ++$p);
                }
                self::expect($tokens, $p, ')');

                [$min, $max] = self::FUNCTIONS[$function];
                if (count($arguments) < $min || count($arguments) > $max) {
                    throw new FormulaException('Function ' . $function . '() called with ' . count($arguments) . ' arguments');
                }

                return static fn(array $v): mixed => $function(...array_map(static fn(\Closure $a): mixed => $a($v), $arguments));

            default:
                throw new FormulaException('Unexpected "' . $token['v'] . '" in formula');
        }
    }

    /**
     * Consumes the expected token or throws.
     *
     * @param list<array{t: string, v: string}> $tokens
     */
    private static function expect(array $tokens, int &$p, string $type): void
    {
        if (($tokens[$p]['t'] ?? null) !== $type) {
            throw new FormulaException('Expected "' . $type . '" in formula');
        }
        ++$p;
    }
}
