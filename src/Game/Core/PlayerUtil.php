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

namespace App\Game\Core;

use App\Game\Core\Player\PlanetPositions;
use App\Game\Core\Player\PlanetManager;
use App\Game\Core\Player\PlanetQueries;
use App\Game\Core\Player\PlanetReshuffle;
use App\Game\Core\Player\PlayerRegistration;
use App\Game\Core\Player\PlayerDeletion;
use App\Game\Core\Player\PlayerMessenger;
use App\Game\Core\Player\VacationMode;
use App\Game\Core\Player\PlayerLookup;
use App\Game\Core\Player\ProtectionRules;
use App\Game\Core\Player\HonorPoints;
use App\Game\Core\Player\PlayerCharts;

/**
 * Facade over the player services in App\Game\Core\Player (kept so the many existing call sites keep
 * working). Every method delegates one to one with the same signature; the logic lives in:
 *
 * - PlanetPositions: free-slot checks, position strategies (random/block/linear), planet limits
 * - PlanetManager: create/delete planets and moons, clear planets
 * - PlanetReshuffle: admin reshuffle of all home planets
 * - PlanetQueries: main planet, planet list, field limit
 * - PlayerRegistration: account-to-universe player creation, name validation
 * - PlayerDeletion: player removal incl. alliance/buddy cleanup
 * - PlayerMessenger: sending and forwarding messages
 * - VacationMode: entering/leaving vacation mode
 * - PlayerLookup: colour/stb settings, buddy/block status, auth level, account lookups
 * - ProtectionRules: catch-up factor, noob/strong protection, player status
 * - HonorPoints: honor points of fights and the honor ranking
 * - PlayerCharts: points charts of players and alliances
 */
class PlayerUtil
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    public function isPositionFree(int $universe, int $galaxy, int $system, int $planet, string $type = TYPE_PLANET): bool
    {
        return $this->planetPositions()->isPositionFree($universe, $galaxy, $system, $planet, $type);
    }

    public function isNameValid(string $name, bool $isPlanetName = false): bool
    {
        return $this->playerRegistration()->isNameValid($name, $isPlanetName);
    }

    public function checkPosition(int $universe, int $galaxy, int $system, int $planet): bool
    {
        return $this->planetPositions()->checkPosition($universe, $galaxy, $system, $planet);
    }

    public function getPlanetDataPlanetConfig(): array
    {
        return $this->planetManager()->getPlanetDataPlanetConfig();
    }

    public function randomHP(int $universe): array
    {
        return $this->planetPositions()->randomHP($universe);
    }

    public function blockHP(int $universe): array
    {
        return $this->planetPositions()->blockHP($universe);
    }

    public function linearHP(int $universe, Config $config): array
    {
        return $this->planetPositions()->linearHP($universe, $config);
    }

    public function getNewPlanetPosition(int $universe): array
    {
        return $this->planetPositions()->getNewPlanetPosition($universe);
    }

    public function createPlayer(
        int $accountId,
        int $universe,
        string $userName,
        ?string $userLanguage = null,
        ?int $galaxy = null,
        ?int $system = null,
        ?int $planet = null,
        ?string $name = null,
        int $authlevel = 0,
        ?string $userIpAddress = null,
        ?string $stb = null,
        ?string $colorSettings = null,
        ?string $missionSettings = null,
        ?string $timezone = null,
        ?string $imageTheme = null,
        ?string $cssTheme = null,
    ): array {
        return $this->playerRegistration()->createPlayer($accountId, $universe, $userName, $userLanguage, $galaxy, $system, $planet, $name, $authlevel, $userIpAddress, $stb, $colorSettings, $missionSettings, $timezone, $imageTheme, $cssTheme);
    }

    public function createPlanet(
        int $galaxy,
        int $system,
        int $planet,
        int $universe,
        int $userId,
        ?string $name = null,
        bool $isHome = false,
        int $authlevel = 0,
    ): int {
        return $this->planetManager()->createPlanet($galaxy, $system, $planet, $universe, $userId, $name, $isHome, $authlevel);
    }

    public function createMoon(
        int $universe,
        int $galaxy,
        int $system,
        int $planet,
        int $userId,
        float $chance,
        ?string $moonName = null,
        ?int $diameter = null,
    ): int {
        return $this->planetManager()->createMoon($universe, $galaxy, $system, $planet, $userId, $chance, $moonName, $diameter);
    }

    public function removeFromAlliance(array $userData): void
    {
        $this->playerDeletion()->removeFromAlliance($userData);
    }

    public function removeFromBuddy(int $userId): void
    {
        $this->playerDeletion()->removeFromBuddy($userId);
    }

    public function deletePlayer(int $userId): bool
    {
        return $this->playerDeletion()->deletePlayer($userId);
    }

    public function reshuffleAllPlanets(int $universe): string|bool
    {
        return $this->planetReshuffle()->reshuffleAllPlanets($universe);
    }

    public function deletePlanet(int $planetId): bool
    {
        return $this->planetManager()->deletePlanet($planetId);
    }

    public function maxPlanetCount(array $USER): int
    {
        return $this->planetPositions()->maxPlanetCount($USER);
    }

    public function currentPlanetCount(array $USER): int
    {
        return $this->planetPositions()->currentPlanetCount($USER);
    }

    public function allowPlanetPosition(int $position, ?array $USER = null, int $universe = ROOT_UNI): bool
    {
        return $this->planetPositions()->allowPlanetPosition($position, $USER, $universe);
    }

    public function forwardMessage(int|array $userId, int $senderId, int $messageId): void
    {
        $this->playerMessenger()->forwardMessage($userId, $senderId, $messageId);
    }

    public function sendMessage(
        int|array $userId,
        int $senderId,
        string $senderName,
        int $messageType,
        string $subject,
        string $text,
        int $time,
        int $unread = 1,
        ?int $universe = null,
        ?string $groupKey = null,
    ): void {
        $this->playerMessenger()->sendMessage($userId, $senderId, $senderName, $messageType, $subject, $text, $time, $unread, $universe, $groupKey);
    }

    public function clearPlanets(array $USER): void
    {
        $this->planetManager()->clearPlanets($USER);
    }

    public function disable_vmode(array &$USER, ?array &$PLANET = null): void
    {
        $this->vacationMode()->disable_vmode($USER, $PLANET);
    }

    public function enable_vmode(array &$USER, ?array &$PLANET, bool $byBan = false): void
    {
        $this->vacationMode()->enable_vmode($USER, $PLANET, $byBan);
    }

    public function player_colors(?array $USER = null): array
    {
        return $this->playerLookup()->player_colors($USER);
    }

    public function player_stb_settings(?array $USER = null): array
    {
        return $this->playerLookup()->player_stb_settings($USER);
    }

    public function player_signal_colors(?array $USER = null): array
    {
        return $this->playerLookup()->player_signal_colors($USER);
    }

    public function get_buddy_status(int $userId, int $targetUserId): bool
    {
        return $this->playerLookup()->get_buddy_status($userId, $targetUserId);
    }

    public function get_block_status(int $userId, int $targetUserId): array
    {
        return $this->playerLookup()->get_block_status($userId, $targetUserId);
    }

    public function get_authlevel(int $userId): int
    {
        return $this->playerLookup()->get_authlevel($userId);
    }

    public function get_account_id(int $userId): int
    {
        return $this->playerLookup()->get_account_id($userId);
    }

    public function calculate_honorpoints_single(int $attacker, int $defender, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        return $this->honorPoints()->calculate_honorpoints_single($attacker, $defender, $attackerUnitsLost, $defenderUnitsLost);
    }

    public function calculate_honorpoints_multi(array $attackers, array $defenders, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        return $this->honorPoints()->calculate_honorpoints_multi($attackers, $defenders, $attackerUnitsLost, $defenderUnitsLost);
    }

    public function compare_honor_fights(array $userFights, array $referenceFights): bool
    {
        return $this->honorPoints()->compare_honor_fights($userFights, $referenceFights);
    }

    public function get_honor_data(int $userId): array
    {
        return $this->honorPoints()->get_honor_data($userId);
    }

    public function calculate_honorpoints_boost(int $userId): float
    {
        return $this->honorPoints()->calculate_honorpoints_boost($userId);
    }

    public function calculate_honorpoints_decrease(int $userId): float
    {
        return $this->honorPoints()->calculate_honorpoints_decrease($userId);
    }

    public function get_honor_percent(string $kind = "top"): array
    {
        return $this->honorPoints()->get_honor_percent($kind);
    }

    public function getCatchUpFactor(array $USER): float
    {
        return $this->protectionRules()->getCatchUpFactor($USER);
    }

    public function getCatchUpFactorForElement(float $factor, int $elementId, int $currentLevel): float
    {
        return $this->protectionRules()->getCatchUpFactorForElement($factor, $elementId, $currentLevel);
    }

    public function spyTechAllowed(int $necessaryLevel, int $PlayerID, array $USER): bool
    {
        return $this->protectionRules()->spyTechAllowed($necessaryLevel, $PlayerID, $USER);
    }

    public function getChartPoints(int $targetUserId, array $USER, int $timespan = TIME_1_WEEK): array
    {
        return $this->playerCharts()->getChartPoints($targetUserId, $USER, $timespan);
    }

    public function getAllianceChartPoints(int $allyId, array $USER, int $timespan = TIME_1_MONTH * 12): array
    {
        return $this->playerCharts()->getAllianceChartPoints($allyId, $USER, $timespan);
    }

    public function getPlayerById(int $playerId): ?array
    {
        return $this->playerLookup()->getPlayerById($playerId);
    }

    public function getUniverse(int $userId): int
    {
        return $this->playerLookup()->getUniverse($userId);
    }

    public function getLanguage($language = null, $userID = null)
    {
        return $this->playerLookup()->getLanguage($language, $userID);
    }

    public function getPlanets($USER)
    {
        return $this->planetQueries()->getPlanets($USER);
    }

    public function getMainPlanet(array $user): array
    {
        return $this->planetQueries()->getMainPlanet($user);
    }

    public function calculateMaxPlanetFields($planet)
    {
        return $this->planetQueries()->calculateMaxPlanetFields($planet);
    }

    public function userStatus(array $data, bool|array $noob_protection = false): array
    {
        return $this->protectionRules()->userStatus($data, $noob_protection);
    }

    public function checkNoobProtection($OwnerPlayer, $TargetPlayer)
    {
        return $this->protectionRules()->checkNoobProtection($OwnerPlayer, $TargetPlayer);
    }

    private function planetPositions(): PlanetPositions
    {
        return $this->gameUtility(PlanetPositions::class);
    }

    private function planetManager(): PlanetManager
    {
        return $this->gameUtility(PlanetManager::class);
    }

    private function playerRegistration(): PlayerRegistration
    {
        return $this->gameUtility(PlayerRegistration::class);
    }

    private function playerDeletion(): PlayerDeletion
    {
        return $this->gameUtility(PlayerDeletion::class);
    }

    private function playerMessenger(): PlayerMessenger
    {
        return $this->gameUtility(PlayerMessenger::class);
    }

    private function vacationMode(): VacationMode
    {
        return $this->gameUtility(VacationMode::class);
    }

    private function playerLookup(): PlayerLookup
    {
        return $this->gameUtility(PlayerLookup::class);
    }

    private function protectionRules(): ProtectionRules
    {
        return $this->gameUtility(ProtectionRules::class);
    }

    private function honorPoints(): HonorPoints
    {
        return $this->gameUtility(HonorPoints::class);
    }

    private function playerCharts(): PlayerCharts
    {
        return $this->gameUtility(PlayerCharts::class);
    }

    private function planetReshuffle(): PlanetReshuffle
    {
        return $this->gameUtility(PlanetReshuffle::class);
    }

    private function planetQueries(): PlanetQueries
    {
        return $this->gameUtility(PlanetQueries::class);
    }
}
