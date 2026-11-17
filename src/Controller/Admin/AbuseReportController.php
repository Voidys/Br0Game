<?php

declare(strict_types=1);

namespace App\Controller\Admin;

use App\Game\Admin\AbuseReport\AbuseReportQuery;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only abuse report of the universe the admin acts in: one-sided transports/holds, repeated market trades with a
 * skewed value ratio, rebuild-protection grants and recalls at or after the arrival time, with link markers (shared
 * IP / multi group). Filter: `days` (look-back window, default 7).
 */
#[\App\Routing\GamePage('admin.php', 'abuseReport')]
#[\App\Routing\GamePage('admin.php', 'abuseReport', uniPrefix: true)]
class AbuseReportController extends AbstractAdminController
{
    /**
     * Renders the four report sections for the last `days` days (1-90). Nothing is written.
     */
    public function show(): Response
    {
        $LNG = $this->state->LNG;
        $now = $this->state->runtime->timestamp;
        $days = (int) $this->http()->_GP('days', AbuseReportQuery::DEFAULT_DAYS);

        $report = (new AbuseReportQuery($this->db, $this->elements()))->report((int) $this->universe()->getEmulated(), $days, $now);

        $date = fn(int $time): string => $this->viewHelpers()->formatDate($LNG['php_tdformat'], $time, $this->state->USER['timezone'] ?? null);
        foreach (['transfers', 'trades'] as $section) {
            foreach ($report[$section] as &$row) {
                $row['lastDate'] = $date((int) $row['last']);
            }
            unset($row);
        }
        foreach ($report['protections'] as &$row) {
            $row['grantedDate'] = $date($row['granted']);
        }
        unset($row);
        foreach ($report['recalls'] as &$row) {
            $row['stayDate'] = $date((int) $row['stay_time']);
            $row['arrivalDate'] = $date((int) $row['arrival_time']);
        }
        unset($row);

        $this->assign([
            'report' => $report,
            'days' => $report['days'],
            'minTransfer' => AbuseReportQuery::MIN_TRANSFER_VALUE,
            'returnShare' => (int) round(AbuseReportQuery::MAX_RETURN_SHARE * 100),
            'minTrades' => AbuseReportQuery::MIN_REPEATED_TRADES,
            'outlierPercent' => (int) round((AbuseReportQuery::TRADE_OUTLIER_RATIO - 1) * 100),
            'maxDays' => AbuseReportQuery::MAX_DAYS,
        ]);

        return $this->render('admin/AbuseReportPage.html.twig');
    }
}
