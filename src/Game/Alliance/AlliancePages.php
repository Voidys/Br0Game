<?php

namespace App\Game\Alliance;

use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Template variables of the two alliance overview pages: the public info page of any alliance and the home page of the
 * acting user's own alliance. Composes the other alliance services (texts, statistics, diplomacy, counters).
 */
class AlliancePages
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
        private readonly AllianceStatistics $statistics,
        private readonly AllianceDiplomacyService $diplomacy,
        private readonly AllianceMemberLists $memberLists,
    ) {}

    /**
     * Variables of the info page of an alliance (visible for everybody in the universe): descriptive data, treaties and
     * diplomats when the alliance publishes diplomacy, fight statistics when it publishes them, whether the viewer may
     * apply, and the points chart. The internal text is never part of it.
     *
     * @param array<string, mixed> $data the alliance row
     * @param bool $hasAlliance the viewer is member of an alliance
     * @param bool $hasApply the viewer has an open application
     * @return array<string, mixed>
     */
    public function info(array $data, bool $hasAlliance, bool $hasApply): array
    {
        $LNG = & $this->state->LNG;
        $USER = & $this->state->USER;

        $diplomaticData = false;
        $diplomats = false;
        if ($data['ally_diplo'] == 1) {
            $diplomaticData = $this->diplomacy->relations($data);
            $diplomats = $this->repository->diplomats($data['id']);
        }

        $stats = $this->statistics->forInfoPage($data);
        $statisticData = $stats['data'];
        $userPoints = $this->repository->userPoints($USER['id']);

        return [
            'diplomaticData' => $diplomaticData,
            'diplomats' => $diplomats,
            'statisticData' => $statisticData,
            'ally_description' => isset($data['ally_description']) ? AllianceText::multiline($data['ally_description']) : '',
            'ally_id' => $data['id'],
            'ally_image' => AllianceText::httpUrlOrEmpty((string) $data['ally_image']),
            'ally_web' => AllianceText::httpUrlOrEmpty((string) $data['ally_web']),
            'ally_member_scount' => $data['ally_members'],
            'ally_max_members' => $data['ally_max_members'],
            'ally_name' => $data['ally_name'],
            'ally_tag' => $data['ally_tag'],
            'ally_stats' => $data['ally_stats'],
            'ally_diplo' => $data['ally_diplo'],
            'ally_request' => !$hasAlliance
                && !$hasApply
                && $data['ally_request_notallow'] == 0
                && $data['ally_max_members'] > $data['ally_members'],
            'ally_request_min_points' => $userPoints >= $data['ally_request_min_points'],
            'ally_request_min_points_info' => sprintf(
                $LNG['al_requests_min_points'],
                $this->viewHelpers()->prettyNumber($data['ally_request_min_points']),
            ),
            'show' => $stats['show'],
            'totalfight' => $statisticData['totalfight'],
            'fightwon' => $statisticData['fightwon'],
            'fightlose' => $statisticData['fightlose'],
            'fightdraw' => $statisticData['fightdraw'],
            'unitsshot' => $statisticData['unitsshot'],
            'unitslose' => $statisticData['unitslose'],
            'dermetal' => $statisticData['dermetal'],
            'dercrystal' => $statisticData['dercrystal'],
            'chartData' => $this->statistics->chart($data),
        ];
    }

    /**
     * Variables of the home page of the acting user's own alliance: both texts, own rank, open applications and treaty
     * requests (counters for the manager shortcuts), diplomats, treaties, fight statistics and the points chart.
     *
     * @param array<string, mixed> $data the alliance row
     * @return array<string, mixed>
     */
    public function home(array $data): array
    {
        $USER = & $this->state->USER;
        $LNG = & $this->state->LNG;

        $applyCount = $this->repository->countApplications($data['id']);
        $diploCountIn = $this->repository->countOpenDiplomacy($data['id'], true);
        $diploCountOut = $this->repository->countOpenDiplomacy($data['id'], false);
        $stats = $this->statistics->forHomePage($data);
        $fightdata = $stats['fightdata'];

        return [
            'DiploInfo' => $this->diplomacy->relations($data),
            'ally_web' => AllianceText::httpUrlOrEmpty((string) $data['ally_web']),
            'ally_tag' => $data['ally_tag'],
            'ally_members' => $data['ally_members'],
            'ally_max_members' => $data['ally_max_members'],
            'ally_name' => $data['ally_name'],
            'ally_image' => AllianceText::httpUrlOrEmpty((string) $data['ally_image']),
            'ally_description' => isset($data['ally_description']) ? AllianceText::multiline($data['ally_description']) : '',
            'ally_text' => isset($data['ally_text']) ? AllianceText::multiline($data['ally_text']) : '',
            'rankName' => $this->memberLists->ownRankName($data),
            'requests' => sprintf($LNG['al_new_requests'], $applyCount),
            'applyCount' => $applyCount,
            'diplomats' => $this->repository->diplomats($data['id']),
            'diploRequestsIn' => $LNG['al_diplo_accept'] . ' - ' . $diploCountIn,
            'diploRequestsOut' => $LNG['al_diplo_accept_send'] . ' - ' . $diploCountOut,
            'diploCountIn' => $diploCountIn,
            'diploCountOut' => $diploCountOut,
            'totalfight' => $fightdata['totalfight'],
            'fightwon' => $fightdata['wons'],
            'fightlose' => $fightdata['loos'],
            'fightdraw' => $fightdata['draws'],
            'unitsshot' => $this->viewHelpers()->prettyNumber($fightdata['desunits']),
            'unitslose' => $this->viewHelpers()->prettyNumber($fightdata['lostunits']),
            'dermetal' => $this->viewHelpers()->prettyNumber($fightdata['kbmetal']),
            'dercrystal' => $this->viewHelpers()->prettyNumber($fightdata['kbcrystal']),
            'isOwner' => $data['ally_owner'] == $USER['id'],
            'show' => $stats['show'],
            'chartData' => $this->statistics->chart($data),
        ];
    }
}
