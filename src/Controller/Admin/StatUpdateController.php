<?php

/**
 *  2Moons
 *   by Jan-Otto Kröpke 2009-2016
 * For the full copyright and license information, please view the LICENSE
 * @package 2Moons
 * @author Jan-Otto Kröpke <slaver7@gmail.com>
 * @copyright 2009 Lucky
 * @copyright 2016 Jan-Otto Kröpke <slaver7@gmail.com>
 * @licence MIT
 * @version 1.8.0
 * @link https://github.com/jkroepke/2Moons
 */

namespace App\Controller\Admin;

use App\Routing\GameRoute;
use Symfony\Component\HttpFoundation\Response;
use Exception;
use App\Game\Core\Discord;
use App\Game\Core\Statbuilder;

/**
 * Recalculates the statistics (points) manually and shows the result.
 */
class StatUpdateController extends AbstractAdminController
{
    /**
     * Runs the statistics build (Statbuilder::generateStats) in the request, reports duration, memory and SQL count, and logs the result
     * to Discord. Failures are shown and logged as well.
     */
    #[GameRoute('admin', 'admin/stat-update', 'statUpdate', 'run', ['POST'])]
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        try {
            $stat = new Statbuilder($this->db, $this->configs, $this->state);
            $result = $stat->generateStats();
            $memory_p = str_replace(["%p", "%m"], $result['memory_peak'], $LNG['sb_top_memory']);
            $memory_e = str_replace(["%e", "%m"], $result['end_memory'], $LNG['sb_final_memory']);
            $memory_i = str_replace(["%i", "%m"], $result['initial_memory'], $LNG['sb_start_memory']);
            $stats_end_time = $LNG->format('sb_stats_update', $result['totaltime']);
            $stats_sql = $LNG->format('sb_sql_counts', $result['sql_count']);

            $this->discord()->sendLog('Statistik (manual)', $result);

            // the translations carry <br>; all dynamic parts are numbers
            return $this->messageResponse(new \Twig\Markup($LNG['sb_stats_updated'] . $stats_end_time . $memory_i . $memory_e . $memory_p . $stats_sql, 'UTF-8'), $this->router->generate('admin_maintenance_show'), 0);
        } catch (Exception $exception) {
            $this->discord()->sendLog('Statistik (manual) FAILED', null, $exception);
            return $this->messageResponse($exception->getMessage(), $this->router->generate('admin_maintenance_show'), 0, true);
        }
    }
}
