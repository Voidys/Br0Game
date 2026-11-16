<?php

namespace App\Game\Alliance;

use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Database;
use App\Game\Core\GameUtilities;
use App\Game\Core\Singleton;

/**
 * Battle statistics and point charts of an alliance for the alliance info and home pages.
 *
 * Rule: with the "spy tech dependent stats" module only players with spy tech 6+ (or staff) see the fight
 * statistics of an alliance.
 */
class AllianceStatistics
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
        private readonly AllianceRepository $repository,
    ) {}

    /**
     * Statistics block of the public alliance info page (formatted numbers). Zeroed when the alliance hides its
     * statistics (ally_stats = 0) or the viewer's spy tech is too low (then `show` is false).
     *
     * @param array<string, mixed> $alliance
     * @return array{show: bool, data: array<string, mixed>} keys of data: totalfight, fightwon, fightlose,
     *         fightdraw, unitsshot, unitslose, dermetal, dercrystal
     */
    public function forInfoPage(array $alliance): array
    {
        $USER = $this->state->USER;
        $statisticData = [
            'totalfight' => 0,
            'fightwon' => 0,
            'fightlose' => 0,
            'fightdraw' => 0,
            'unitsshot' => 0,
            'unitslose' => 0,
            'dermetal' => 0,
            'dercrystal' => 0,
        ];
        $show = true;
        if ($alliance['ally_stats'] == 1) {
            if ($this->mayViewFightStats($USER)) {
                $statisticResult = $this->summedStats($alliance);

                $statisticData = [
                    'totalfight' => $statisticResult['wons'] + $statisticResult['loos'] + $statisticResult['draws'],
                    'fightwon' => $statisticResult['wons'],
                    'fightlose' => $statisticResult['loos'],
                    'fightdraw' => $statisticResult['draws'],
                    'unitsshot' => $this->viewHelpers()->prettyNumber($statisticResult['desunits']),
                    'unitslose' => $this->viewHelpers()->prettyNumber($statisticResult['lostunits']),
                    'dermetal' => $this->viewHelpers()->prettyNumber($statisticResult['kbmetal']),
                    'dercrystal' => $this->viewHelpers()->prettyNumber($statisticResult['kbcrystal']),
                ];
            } else {
                $show = false;
            }
        }

        return ['show' => $show, 'data' => $statisticData];
    }

    /**
     * Statistics block of the own alliance's home page (members always see it, unless the spy tech rule hides it).
     *
     * @param array<string, mixed> $alliance
     * @return array{show: bool, fightdata: array<string, mixed>} keys of fightdata: totalfight, wons, loos, draws,
     *         kbmetal, kbcrystal, lostunits, desunits (raw numbers, 0 when hidden)
     */
    public function forHomePage(array $alliance): array
    {
        $USER = $this->state->USER;
        $statisticResult = $this->summedStats($alliance);

        if ($this->mayViewFightStats($USER)) {
            return ['show' => true, 'fightdata' => [
                'totalfight' => $statisticResult['wons'] + $statisticResult['loos'] + $statisticResult['draws'],
                'wons' => $statisticResult['wons'],
                'loos' => $statisticResult['loos'],
                'draws' => $statisticResult['draws'],
                'kbmetal' => $statisticResult['kbmetal'],
                'kbcrystal' => $statisticResult['kbcrystal'],
                'lostunits' => $statisticResult['lostunits'],
                'desunits' => $statisticResult['desunits'],
            ]];
        }

        return ['show' => false, 'fightdata' => [
            'totalfight' => 0,
            'wons' => 0,
            'loos' => 0,
            'draws' => 0,
            'kbmetal' => 0,
            'kbcrystal' => 0,
            'lostunits' => 0,
            'desunits' => 0,
        ]];
    }

    /** points history (12 months) of the alliance for the chart; `userId`-relative like the player charts */
    public function chart(array $alliance): array
    {
        return $this->playerUtil()->getAllianceChartPoints($alliance['id'], $this->state->USER);
    }

    private function mayViewFightStats(array $user): bool
    {
        return !$this->viewHelpers()->isModuleAvailable(MODULE_SPYTECH_DEPENDENT_STATS)
            || $user['spy_tech'] >= 6 || $user['authlevel'] > 0;
    }

    /** wins, losses, draws, debris and unit counters summed over all members */
    private function summedStats(array $alliance): array
    {
        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);

        return $statsHandler->getSummedStats($this->repository->memberIds($alliance['id']), [
            AdvancedStatsEnum::WONS,
            AdvancedStatsEnum::LOOS,
            AdvancedStatsEnum::DRAWS,
            AdvancedStatsEnum::KBMETAL,
            AdvancedStatsEnum::KBCRYSTAL,
            AdvancedStatsEnum::LOSTUNITS,
            AdvancedStatsEnum::DESUNITS,
        ]);
    }
}
