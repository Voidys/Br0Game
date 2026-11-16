<?php

namespace App\Controller\Game;

use Symfony\Component\HttpFoundation\Response;
use App\Game\Core\Config;
use App\Game\Core\Database;
use App\Game\Core\HTTP;
use App\Game\Core\MarketManager;
use App\Game\Core\PlayerUtil;
use App\Game\Core\Singleton;
use App\Game\Core\SpyReport;
use App\Game\Core\Universe;

/**
 * Popup with the latest spy reports of a coordinate (opened from the galaxy view).
 */
#[\App\Routing\GamePage('game.php', 'spyReportPopup')]
#[\App\Routing\GamePage('game.php', 'spyReportPopup', uniPrefix: true)]
class SpyReportPopupController extends AbstractGameController
{
    public static $requireModule = 'MODULE_MESSAGES';

    public function initialize(): void {}

    public function show(): Response
    {
        $USER = $this->state->USER;
        $LNG = $this->state->LNG;
        $this->setWindow('popup');

        $galaxy = $this->http()->_GP('galaxy', 0);
        $system = $this->http()->_GP('system', 0);
        $planet = $this->http()->_GP('planet', 0);
        $planetType = $this->http()->_GP('planettype', 1);

        $db = $this->db;

        $sql = "SELECT m.message_text, m.message_time
            FROM %%MESSAGES%% m
            INNER JOIN %%USERS_TO_MESSAGES%% um ON m.message_id = um.message_id
            INNER JOIN %%PLANETS%% p ON m.message_group = CONCAT('m_', p.id)
            WHERE m.message_type = :messageType
              AND um.user_id = :userId
              AND um.deleted_at IS NULL
              AND p.galaxy = :galaxy
              AND p.system = :system
              AND p.planet = :planet
              AND p.planet_type = :planetType
              AND p.universe = :universe
            ORDER BY m.message_time DESC
            LIMIT 10;";

        $rows = $db->select($sql, [
            ':messageType' => MESSAGE_TYPE_SPY_REPORT,
            ':userId'      => $USER['id'],
            ':galaxy'      => $galaxy,
            ':system'      => $system,
            ':planet'      => $planet,
            ':planetType'  => $planetType,
            ':universe'    => $this->universe()->current(),
        ]);

        $spyReports = [];
        foreach ($rows as $row) {
            $spyReports[] = [
                'html' => $this->spyReport()->getSpyReport($row['message_text']),
                'date' => $this->viewHelpers()->formatDate("M D d H:i:s", $row['message_time'], $USER['timezone']),
            ];
        }

        $config = $this->configs->get();
        $fleetIntoDebris = $config->fleet_debris_percentage;
        $defIntoDebris = $config->def_debris_percentage;
        $uniType = $config->uni_type;
        $maxGalaxy = $config->max_galaxy;

        $stbSettings2 = $this->playerUtil()->player_stb_settings($USER);

        $combustion_tech = $USER["combustion_tech"];
        $impulse_motor_tech = $USER["impulse_motor_tech"];

        $pMarket = new MarketManager($this->db, $this->configs, $this->state);
        $refrates = $pMarket->getReferenceRatios();

        $PlanetSelect = [];
        foreach ($this->playerUtil()->getPlanets($USER) as $PlanetQuery) {
            $PlanetSelect[$PlanetQuery['id']] = $PlanetQuery['name'] . (($PlanetQuery['planet_type'] == 3) ? " (" . $LNG['fcm_moon'] . ")" : "") . " [" . $PlanetQuery['galaxy'] . ":" . $PlanetQuery['system'] . ":" . $PlanetQuery['planet'] . "]";
        }

        $this->addScript('message.js');
        $this->assign([
            'spyReports'            => $spyReports,
            'galaxy'                => $galaxy,
            'system'                => $system,
            'planet'                => $planet,
            'servertime'            => $this->viewHelpers()->formatDate("M D d H:i:s", $this->state->runtime->timestamp, $USER['timezone']),
            'stb_big_time'          => $stbSettings2['stb_big_time'],
            'stb_med_time'          => $stbSettings2['stb_med_time'],
            'stb_small_time'        => $stbSettings2['stb_small_time'],
            'stb_big_ress'          => $stbSettings2['stb_big_ress'],
            'stb_med_ress'          => $stbSettings2['stb_med_ress'],
            'stb_small_ress'        => $stbSettings2['stb_small_ress'],
            'refratesMetal'         => $refrates['metal'],
            'refratesCrystal'       => $refrates['crystal'],
            'refratesDeuterium'     => $refrates['deuterium'],
            'impulse_motor_tech'    => $impulse_motor_tech,
            'combustion_tech'       => $combustion_tech,
            'fleetIntoDebris'       => $fleetIntoDebris,
            'defIntoDebris'         => $defIntoDebris,
            'uniType'               => $uniType,
            'maxGalaxy'             => $maxGalaxy,
            'PlanetSelect'          => $PlanetSelect,
        ]);

        return $this->render('game/page.spyReportPopup.default.html.twig');
    }
}
