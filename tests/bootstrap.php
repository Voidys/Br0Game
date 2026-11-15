<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__) . '/vendor/autoload.php';

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv(dirname(__DIR__) . '/.env');

$root = dirname(__DIR__);

// The game bootstrap wants config/game.php (salt, installer marker). It is gitignored, so a fresh checkout (CI) has none:
// write the sample for the duration of the run.
$gameConfig = $root . '/config/game.php';
if (!is_file($gameConfig) || filesize($gameConfig) === 0) {
    file_put_contents($gameConfig, sprintf(
        (string) file_get_contents($root . '/config/game.sample.php'),
        'localhost',
        '3306',
        'test',
        'test',
        'test_test',
        'uni1_',
        'testpw',
        'aXNo9mCgWO3zIG86wSV.nx',
    ));
    register_shutdown_function(static fn() => @unlink($gameConfig));
}

// Integration tests need the schema: build it in a child process (the game reads its connection at start-up, and the
// installer boot must not share a process with the CLI boot the tests use). Skipped for `--testsuite Unit` (no database
// needed) and with SKIP_TEST_DB_SETUP=1.
$argv = $_SERVER['argv'] ?? [];
$unitOnly = in_array('--testsuite=Unit', $argv, true) || (($i = array_search('--testsuite', $argv, true)) !== false && ($argv[$i + 1] ?? '') === 'Unit');
if (getenv('SKIP_TEST_DB_SETUP') !== '1' && !$unitOnly) {
    passthru(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . '/Support/setup-database.php'), $status);
    if ($status !== 0) {
        fwrite(STDERR, "Test database setup failed.\n");
        exit(1);
    }
}
