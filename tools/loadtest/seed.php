<?php

declare(strict_types=1);

/**
 * Seeds and resets the test data of the concurrency load test (see tools/loadtest/README.md).
 *
 *   players <n>                                create n players "lt_<nr>" (account lt_<nr>@loadtest.invalid, password "loadtest") with
 *                                              rich planets, fleets and defence in universe 1
 *   waves <count> <from> <to> <flight>         launch <count> short flights (transport / espionage / attack between the test players)
 *                                              whose arrival is spread over now+<from> .. now+<to> seconds in clusters of the same
 *                                              second, each returning <flight> seconds after the arrival
 *   reset                                      deletes all test players with their planets, fleets, messages, accounts
 *
 * It writes to the database the game is configured for. Run it as APP_ENV=test (the test container exposes the
 * game services): ddev exec env APP_ENV=test DATABASE_URL='mysql://db:db@db:3306/db?serverVersion=8.4.13&charset=utf8mb4' php tools/loadtest/seed.php ...
 */

use App\Game\Core\AccountUtil;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\FleetLauncher;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Kernel;
use App\Runtime\GameKernel;
use Symfony\Component\Dotenv\Dotenv;
use App\Game\Enum\Mission;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
chdir(dirname(__DIR__, 2));

(new Dotenv())->bootEnv(dirname(__DIR__, 2) . '/.env');

$kernel = new Kernel($_SERVER['APP_ENV'] ?? 'test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$container->get(Singleton::class)->time = ['start' => microtime(true), 'db' => 0.0];
$container->get(GameKernel::class)->bootCli();

/** @var Database $db */
$db = $container->get(Database::class);
$configs = $container->get(ConfigRegistry::class);
$state = $container->get(Singleton::class);

const PREFIX = 'lt_';
const MAIL_DOMAIN = '@loadtest.invalid';

$command = $argv[1] ?? '';
$players = new PlayerUtil($db, $configs, $state);

/** @return list<array<string, mixed>> */
$loadPlayers = static fn(): array => $db->select(
    'SELECT u.id, u.username, p.id AS planet_id, p.galaxy, p.`system`, p.planet FROM %%USERS%% u INNER JOIN %%PLANETS%% p ON p.id = u.id_planet WHERE u.username LIKE :prefix ORDER BY u.id;',
    [':prefix' => PREFIX . '%'],
);

switch ($command) {
    case 'players':
        $count = (int) ($argv[2] ?? 50);
        $accounts = new AccountUtil($db, $configs, $state);
        $hash = $accounts->cryptPassword('loadtest'); // the game's own cost: a weaker hash is rehashed at the first login, which logs that session out
        $existing = count($loadPlayers());
        for ($i = $existing + 1; $i <= $existing + $count; $i++) {
            $name = PREFIX . $i;
            $accountId = $accounts->createAccount($name, $hash, $name . MAIL_DOMAIN, 'en');
            [$userId, $planetId] = $players->createPlayer((int) $accountId, 1, $name, 'en');
            $db->update('UPDATE %%PLANETS%% SET metal = 5000000, crystal = 5000000, deuterium = 5000000, small_ship_cargo = 400, big_ship_cargo = 100, light_hunter = 300, heavy_hunter = 100, crusher = 50, spy_sonde = 200, misil_launcher = 200, small_laser = 50, metal_mine = 10, crystal_mine = 8, deuterium_sintetizer = 8, solar_plant = 12, robot_factory = 4, hangar = 6, laboratory = 6 WHERE id = :id;', [':id' => $planetId]);
            $db->update('UPDATE %%USERS%% SET spy_tech = 6, computer_tech = 6, military_tech = 4, defence_tech = 4, shield_tech = 4, combustion_tech = 6, impulse_motor_tech = 4 WHERE id = :id;', [':id' => $userId]);
        }
        echo "created $count players (now " . count($loadPlayers()) . ")\n";
        break;

    case 'waves':
        [$count, $from, $to, $flight] = [(int) ($argv[2] ?? 300), (int) ($argv[3] ?? 10), (int) ($argv[4] ?? 200), (int) ($argv[5] ?? 15)];
        $all = $loadPlayers();
        if (count($all) < 2) {
            fwrite(STDERR, "need test players first\n");
            exit(1);
        }
        $launcher = new FleetLauncher($db, $configs, $state);
        $now = $state->runtime->timestamp;
        // mission => [ships, share of the waves in percent]
        $kinds = [[Mission::Transport->value, [202 => 2], 55], [Mission::Spy->value, [210 => 1], 25], [Mission::Attack->value, [204 => 5, 205 => 1], 20]];
        $perSecond = [];
        for ($i = 0; $i < $count; $i++) {
            $from_ = $all[array_rand($all)];
            do {
                $to_ = $all[array_rand($all)];
            } while ($to_['id'] === $from_['id']);
            $roll = random_int(1, 100);
            foreach ($kinds as [$mission, $ships, $share]) {
                if (($roll -= $share) <= 0) {
                    break;
                }
            }
            // clusters: the arrival second is a multiple of 3 so many fleets become due together
            $arrival = $now + $from + 3 * random_int(0, intdiv(max(3, $to - $from), 3));
            $perSecond[$arrival] = ($perSecond[$arrival] ?? 0) + 1;
            $launcher->sendFleet(
                $ships,
                $mission,
                (int) $from_['id'],
                (int) $from_['planet_id'],
                (int) $from_['galaxy'],
                (int) $from_['system'],
                (int) $from_['planet'],
                1,
                (int) $to_['id'],
                (int) $to_['planet_id'],
                (int) $to_['galaxy'],
                (int) $to_['system'],
                (int) $to_['planet'],
                1,
                [901 => $mission === Mission::Transport->value ? 1000 : 0, 902 => 0, 903 => 0],
                $arrival,
                0,
                $arrival + $flight,
                0,
                0,
                0,
                1.0,
            );
        }
        echo "launched $count flights, max " . max($perSecond) . " due in the same second, arrivals $from..$to s from now\n";
        break;

    case 'reset':
        $deleted = 0;
        foreach ($loadPlayers() as $player) {
            $players->deletePlayer((int) $player['id']);
            $deleted++;
        }
        $db->delete('DELETE FROM %%ACCOUNTS%% WHERE email LIKE :mail;', [':mail' => '%' . MAIL_DOMAIN]);
        echo "deleted $deleted test players\n";
        break;

    default:
        fwrite(STDERR, "usage: seed.php players <n> | waves <count> <from> <to> <flight> | reset\n");
        exit(1);
}
