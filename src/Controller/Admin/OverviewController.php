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

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Singleton;

/** Admin dashboard: security notices, player and universe statistics, system and cron status. */
#[\App\Routing\GamePage('admin.php', 'overview')]
#[\App\Routing\GamePage('admin.php', 'overview', uniPrefix: true)]
class OverviewController extends AbstractAdminController
{
    /**
     * Admin dashboard. Notices for ADM (leftover update.php / webinstall.php / var/ENABLE_INSTALL_TOOL, var/ directories that are not
     * writable), figures of the emulated universe (players online within the last 15 minutes, players, fleets), active cronjobs
     * and a warning for jobs whose lock is still set (stuck runs). Read-only.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $USER = $this->state->USER;
        $Message = [];
        if ($USER['authlevel'] >= AUTH_ADM) {
            if (file_exists($this->state->runtime->rootPath . 'update.php')) {
                $Message[] = $LNG->format('ow_file_detected', 'update.php');
            }

            if (file_exists($this->state->runtime->rootPath . 'webinstall.php')) {
                $Message[] = $LNG->format('ow_file_detected', 'webinstall.php');
            }

            if (file_exists('var/ENABLE_INSTALL_TOOL')) {
                $Message[] = $LNG->format('ow_file_detected', 'var/ENABLE_INSTALL_TOOL');
            }

            if (!$this->canonicalUrl->canBuildLinks()) {
                $Message[] = $LNG['ow_default_uri_missing'];
            }

            // runtime data lives in var/ (game caches, sessions, logs)
            foreach (['var/game', 'var/sessions', 'var/log'] as $dir) {
                if (!is_writable($this->state->runtime->rootPath . $dir)) {
                    $Message[] = $LNG->format('ow_dir_not_writable', $dir);
                }
            }
        }

        // dashboard figures of the emulated universe
        $universe = $this->universe()->getEmulated();
        $now = $this->state->runtime->timestamp;
        $db = $this->db;
        $stats = [
            'online' => (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS%% WHERE universe = :uni AND onlinetime >= :since;', [':uni' => $universe, ':since' => $now - 15 * 60], 'c'),
            'players' => (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%USERS%% WHERE universe = :uni;', [':uni' => $universe], 'c'),
            'fleets' => (int) $db->selectSingle('SELECT COUNT(*) AS c FROM %%FLEETS%% WHERE universe = :uni;', [':uni' => $universe], 'c'),
        ];
        $cronjobs = $db->select('SELECT name, nextTime, `lock` FROM %%CRONJOBS%% WHERE isActive = 1 ORDER BY nextTime;');
        $stuckCronjobs = array_values(array_filter($cronjobs, static fn(array $job): bool => !empty($job['lock'])));
        if ($USER['authlevel'] >= AUTH_ADM && $stuckCronjobs !== []) {
            $Message[] = $LNG->format('adm_dash_cron_locked', implode(', ', array_column($stuckCronjobs, 'name')));
        }
        $config = $this->configs->get($universe);
        $uniStatusLabels = [0 => 'se_uni_status_regopen_gameopen', 1 => 'se_uni_status_regclosed_gameclosed', 2 => 'se_uni_status_regopen_gameclosed', 3 => 'se_uni_status_regclosed_gameopen'];
        $system = [
            'version' => $config->version,
            'php' => PHP_VERSION,
            'uniStatus' => $LNG[$uniStatusLabels[(int) $config->uni_status] ?? ''] ?? (string) $config->uni_status,
            'uniStatusOpen' => (int) $config->uni_status === 0,
            'nextCron' => $cronjobs[0] ?? null,
            'cronjobs' => count($cronjobs),
        ];

        $unprotected = [];
        if ($USER['authlevel'] >= AUTH_ADM) {
            if (!$config->transport_push_check || (float) $config->transport_push_limit <= 0) {
                $unprotected[] = $LNG['adm_dash_unprotected_push'];
            }
            if ((int) $config->honor_pair_cap === 0) {
                $unprotected[] = $LNG['adm_dash_unprotected_honor'];
            }
            if ($config->bash_war_exempt) {
                $unprotected[] = $LNG['adm_dash_unprotected_bash'];
            }
            if (!$config->depot_cost_real_consumption) {
                $unprotected[] = $LNG['adm_dash_unprotected_depot'];
            }
            if ((int) $config->market_push_tolerance > 10) {
                $unprotected[] = $LNG['adm_dash_unprotected_market'];
            }
        }

        $this->assign([
            'unprotected'       => $unprotected,
            'Messages'          => $Message,
            'stats'             => $stats,
            'system'            => $system,
            'nextCronTime'      => $system['nextCron'] ? $this->viewHelpers()->formatDate($LNG['php_tdformat'], (int) $system['nextCron']['nextTime'], $USER['timezone']) : '–',
            'date'              => date('m\_Y', $this->state->runtime->timestamp),
        ]);

        return $this->render('admin/OverviewBody.html.twig');
    }
}
