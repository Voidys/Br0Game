<?php

/**
 * Child process of tests/bootstrap.php: (re)creates the schema of the dedicated test database from
 * install/install.sql + install/migrations (marked applied) + default config + universe 1, the same
 * build tasks as the installer (App\Runtime\InstallService::runBuildTask). Refuses any database whose name
 * does not end in "_test".
 */

use App\Kernel;
use App\Runtime\GameKernel;
use App\Runtime\InstallService;
use Symfony\Component\Dotenv\Dotenv;

$root = dirname(__DIR__, 2);
require $root . '/vendor/autoload.php';
$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
(new Dotenv())->bootEnv($root . '/.env');

$url = parse_url((string) ($_SERVER['DATABASE_URL'] ?? ''));
$dbName = ltrim((string) ($url['path'] ?? ''), '/');
if (!str_ends_with($dbName, '_test')) {
    fwrite(STDERR, "Refusing to set up database '$dbName': the test database name must end in _test.\n");
    exit(1);
}

// create the database if needed, drop every table of it
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $url['host'], $url['port'] ?? 3306),
    rawurldecode($url['user'] ?? ''),
    rawurldecode($url['pass'] ?? ''),
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
);
$pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("DROP DATABASE `$dbName`");
$pdo->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$kernel = new Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$container->get(GameKernel::class)->boot('INSTALL', 'install/index.php');

/** @var InstallService $installer */
$installer = $container->get(InstallService::class);
$installer->useLanguage('en');
foreach (array_keys($installer->buildTasks('en')) as $task) {
    if ($task === 'images') {
        continue;
    }
    $installer->runBuildTask($task, 'en');
}
echo "test database $dbName ready\n";
