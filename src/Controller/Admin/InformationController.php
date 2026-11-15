<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 *
 * For the full copyright and license information, please view the LICENSE
 *
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Admin;

use App\Game\Core\ServerInfo;
use DateTime;
use DateTimeZone;
use Exception;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server and game information for admins: health checks, server, database, game statistics,
 * logs and caches (data from App\Game\Core\ServerInfo).
 */
#[\App\Routing\GamePage('admin.php', 'information')]
#[\App\Routing\GamePage('admin.php', 'information', uniPrefix: true)]
class InformationController extends AbstractAdminController
{
    public function __construct(
        #[Autowire('%kernel.environment%')]
        private readonly string $environment,
        #[Autowire('%kernel.debug%')]
        private readonly bool $debug,
        #[Autowire('%env(default:session_handler_default:SESSION_HANDLER_DSN)%')]
        private readonly string $sessionDsn,
    ) {}

    public function show(): Response
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;
        $config = $this->configs->get();
        $info = $this->serverInfo();
        $helpers = $this->viewHelpers();
        $formatDate = fn(int $ts): string => $helpers->formatDate($LNG['php_tdformat'], $ts, $USER['timezone']);
        $universe = (int) $this->universe()->getEmulated();

        $checks = $info->checks($this->environment, $this->debug, $this->sessionDsn, $formatDate);
        $counts = [ServerInfo::OK => 0, ServerInfo::WARN => 0, ServerInfo::FAIL => 0, ServerInfo::INFO => 0];
        foreach ($checks as $check) {
            ++$counts[$check['status']];
        }

        $server = $info->server();
        $database = $info->database();
        $game = $info->game($universe, $formatDate, (string) $config->timezone);
        $logs = $info->logs($this->environment);

        $gameVersion = $config->version;
        $origHead = $this->state->runtime->rootPath . '.git/ORIG_HEAD';
        if (@is_file($origHead)) {
            $gameVersion .= ' (' . trim((string) @file_get_contents($origHead)) . ')';
        }

        $this->assign([
            'git_issues_link' => $config->git_issues_link,
            'checks' => $checks,
            'checkCounts' => $counts,
            'server' => $server,
            'opcache' => $info->opcacheStatus(),
            'database' => $database,
            'game' => $game,
            'logs' => $logs,
            'timezones' => $this->timezones($config->timezone, $USER['timezone']),
            'vGame' => $gameVersion,
            'gameHost' => $this->state->runtime->httpHost,
        ]);

        return $this->render('admin/ShowInformationPage.html.twig');
    }

    /**
     * Current time in the PHP, game config and admin time zone.
     *
     * @return list<array{key: string, name: string, time: string, offset: string}>
     */
    private function timezones(string $configZone, string $userZone): array
    {
        $rows = [];
        foreach (['php' => (string) ini_get('date.timezone') ?: date_default_timezone_get(), 'config' => $configZone, 'user' => $userZone] as $key => $name) {
            try {
                $zone = new DateTimeZone($name);
            } catch (Exception) {
                $zone = new DateTimeZone(date_default_timezone_get());
            }
            $now = new DateTime('now', $zone);
            $rows[] = ['key' => $key, 'name' => $zone->getName(), 'time' => $now->format('Y-m-d H:i:s'), 'offset' => $now->format('P')];
        }

        return $rows;
    }
}
