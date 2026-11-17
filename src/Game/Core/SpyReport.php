<?php

namespace App\Game\Core;

/**
 * Renders spy reports for the message list: spy report messages store a JSON blob built by MissionCaseSpy;
 * this class turns it into the message title and the HTML report (Twig template game/shared.mission.spyReport).
 */
class SpyReport
{
    use \App\Game\Core\GameUtilities;

    public function __construct(
        protected \App\Game\Core\Database $db,
        protected \App\Game\Core\ConfigRegistry $configs,
        protected \App\Game\Core\Singleton $state,
    ) {}

    /**
     * Title line of a report message (planet, owner, coordinates, spy time) from the JSON text; '' if the text is not valid JSON.
     */
    public function getSpyReportTitle(string $MessageText): string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $LNG->includeData(['FLEET']);
        $reportData = json_decode($MessageText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return '';
        }

        $spyTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $reportData['time'], $USER['timezone'], $LNG);
        return $LNG->format(
            'sys_mess_spy_report_details_' . $reportData['targetPlanet']['planet_type'],
            $reportData['targetPlanet']['name'],
            $reportData['targetUserName'],
            $reportData['targetPlanet']['galaxy'],
            $reportData['targetPlanet']['system'],
            $reportData['targetPlanet']['planet'],
            $spyTime,
        );
    }

    /**
     * HTML of a report from the stored JSON text, in the viewer's theme; the text is returned unchanged if it is not JSON (old-format reports).
     */
    public function getSpyReport(string $MessageText): string
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $LNG->includeData(['FLEET']);
        $reportData = json_decode($MessageText, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return $MessageText;
        }

        $spyTime = $this->viewHelpers()->formatDate($LNG['php_tdformat'], $reportData['time'], $USER['timezone'], $LNG);
        $title = $LNG->format(
            'sys_mess_spy_report_details_' . $reportData['targetPlanet']['planet_type'],
            $reportData['targetPlanet']['name'],
            $reportData['targetUserName'],
            $reportData['targetPlanet']['galaxy'],
            $reportData['targetPlanet']['system'],
            $reportData['targetPlanet']['planet'],
            $spyTime,
        );

        $THEME = new Theme();
        if (!empty($USER['dpath'])) {
            $THEME->setUserTheme($USER['dpath'], $USER['css_dpath'] ?? null);
        }

        // the report is HTML shown inside the message list, so it is compacted like the former output filter did
        $html = $this->gameView()->render('game/shared.mission.spyReport.html.twig', array_replace($reportData, [
            'title'        => $title,
            'dpath'        => $THEME->getTheme(),
            'themeCssPath' => $THEME->getThemeCssPath(),
        ]));

        return \App\View\HtmlWhitespace::trim($html);
    }
}
