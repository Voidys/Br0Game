<?php

/**
 * Turns a PHPUnit JUnit report into GitHub Actions error annotations, so failing tests are visible on the
 * run page (and through the public API) without opening the job log.
 *
 * Usage: php tools/ci/junit-annotations.php build/junit.xml
 */

$file = $argv[1] ?? 'build/junit.xml';
if (!is_file($file)) {
    echo "::warning::no JUnit report at $file\n";
    exit(0);
}

$xml = simplexml_load_file($file);
$count = 0;
foreach ($xml->xpath('//testcase[failure or error]') as $case) {
    $problem = $case->failure ?? $case->error;
    $message = trim((string) $problem);
    $message = preg_replace('/\s+/', ' ', $message);
    $title = (string) $case['class'] . '::' . (string) $case['name'];
    $path = (string) ($case['file'] ?? '');
    $line = (int) ($case['line'] ?? 1);
    // GitHub annotation messages: keep them short and escape the reserved characters
    $message = strtr(mb_substr($message, 0, 900), ['%' => '%25', "\r" => '%0D', "\n" => '%0A']);
    printf("::error file=%s,line=%d,title=%s::%s\n", $path, $line, $title, $message);
    if (++$count >= 40) {
        break;
    }
}
