<?php

declare(strict_types=1);

namespace App\Tests\Support;

/**
 * Golden-value assertions. A test class declares `private const GOLDEN = [...]` (case key => expected value) and calls
 * goldenAssert('key', $actual). Floats are compared with a tiny delta (1e-9), everything else exactly.
 *
 * To (re)capture values from the current code run PHPUnit with GOLDEN_CAPTURE=1: nothing is asserted, the actual
 * values are appended as PHP source lines to var/golden/<TestClass>.php, ready to paste into GOLDEN. Capturing is only
 * done deliberately, on the code that defines the behaviour to pin.
 */
trait GoldenAssertions
{
    protected function goldenAssert(string $key, mixed $actual): void
    {
        if (getenv('GOLDEN_CAPTURE') === '1') {
            $dir = dirname(__DIR__, 2) . '/var/golden';
            is_dir($dir) || mkdir($dir, 0777, true);
            file_put_contents(
                $dir . '/' . (new \ReflectionClass($this))->getShortName() . '.php',
                '        ' . var_export($key, true) . ' => ' . self::export($actual) . ",\n",
                FILE_APPEND,
            );
            $this->addToAssertionCount(1);

            return;
        }

        $golden = (new \ReflectionClass($this))->getReflectionConstant('GOLDEN');
        $expected = $golden === false ? [] : $golden->getValue();
        $this->assertArrayHasKey($key, $expected, "No golden value for '$key' (capture with GOLDEN_CAPTURE=1)");
        $this->assertEqualsWithDelta($expected[$key], $actual, 1e-9, "Golden value '$key' changed");
    }

    /** compact PHP source of a value (short arrays on one line), for the capture output */
    private static function export(mixed $value): string
    {
        if (is_array($value)) {
            $isList = array_is_list($value);
            $parts = [];
            foreach ($value as $key => $item) {
                $parts[] = ($isList ? '' : var_export($key, true) . ' => ') . self::export($item);
            }

            return '[' . implode(', ', $parts) . ']';
        }

        return var_export($value, true);
    }
}
