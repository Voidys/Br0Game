<?php

namespace App\Game\Core;

/**
 * Lazily built game utility objects, sharing the host's injected services.
 * Hosts provide `$db` (Database), `$configs` (ConfigRegistry) and `$state` (Singleton).
 */
trait GameUtilities
{
    /** @var array<class-string, object> */
    private array $gameUtilityInstances = [];

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function gameUtility(string $class): object
    {
        return $this->gameUtilityInstances[$class] ??= new $class($this->db, $this->configs, $this->state);
    }

    protected function http(): HTTP
    {
        return $this->gameUtilityInstances[HTTP::class] ??= new HTTP($this->state);
    }

    /** factory of the typed User/Planet snapshots built from the state arrays */
    protected function models(): \App\Game\Model\ModelFactory
    {
        return $this->gameUtilityInstances[\App\Game\Model\ModelFactory::class] ??= new \App\Game\Model\ModelFactory($this->state);
    }

    protected function playerUtil(): PlayerUtil
    {
        return $this->gameUtility(PlayerUtil::class);
    }

    protected function fleetFunctions(): FleetFunctions
    {
        return $this->gameUtility(FleetFunctions::class);
    }

    protected function buildFunctions(): BuildFunctions
    {
        return $this->gameUtility(BuildFunctions::class);
    }

    protected function accountUtil(): AccountUtil
    {
        return $this->gameUtility(AccountUtil::class);
    }

    protected function expeditionShips(): ExpeditionFindableShipsHandler
    {
        return $this->gameUtility(ExpeditionFindableShipsHandler::class);
    }

    protected function achievement(): Achievement
    {
        return $this->gameUtility(Achievement::class);
    }

    /** Universe needs no database: the installer asks for it before a config exists. */
    protected function universe(): Universe
    {
        return $this->gameUtilityInstances[Universe::class] ??= new Universe($this->state, $this->sessions());
    }

    protected function cronjobs(): \App\Game\Cronjob\Cronjob
    {
        return $this->gameUtility(\App\Game\Cronjob\Cronjob::class);
    }

    /** renderer of the game templates (App\View\GameView) */
    protected function gameView(): \App\View\GameView
    {
        return $this->gameUtility(\App\View\GameView::class);
    }

    protected function serverInfo(): ServerInfo
    {
        return $this->gameUtility(ServerInfo::class);
    }

    protected function sessions(): SessionManager
    {
        return $this->gameUtility(SessionManager::class);
    }

    protected function mailer(): Mail
    {
        return $this->gameUtility(Mail::class);
    }

    /** Discord needs no database: it also reports errors when the database is down. */
    protected function discord(): Discord
    {
        return $this->gameUtilityInstances[Discord::class] ??= new Discord($this->configs, $this->state);
    }

    protected function viewHelpers(): ViewHelpers
    {
        return $this->gameUtility(ViewHelpers::class);
    }

    protected function maintenance(): Maintenance
    {
        return $this->gameUtility(Maintenance::class);
    }

    protected function hangarFilters(): HangarFilters
    {
        return $this->gameUtility(HangarFilters::class);
    }

    protected function allianceDepot(): AllianceDepot
    {
        return $this->gameUtility(AllianceDepot::class);
    }

    protected function resourceUpdate(): ResourceUpdate
    {
        return $this->gameUtility(ResourceUpdate::class);
    }

    protected function spyReport(): SpyReport
    {
        return $this->gameUtility(SpyReport::class);
    }

    protected function battleAchievements(): \App\Game\Achievements\BattleAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\BattleAchievement::class);
    }

    protected function miscAchievements(): \App\Game\Achievements\MiscAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\MiscAchievement::class);
    }

    protected function planetRessAchievements(): \App\Game\Achievements\PlanetRessAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\PlanetRessAchievement::class);
    }

    protected function playerUtilAchievements(): \App\Game\Achievements\PlayerUtilAchievement
    {
        return $this->gameUtility(\App\Game\Achievements\PlayerUtilAchievement::class);
    }
}
