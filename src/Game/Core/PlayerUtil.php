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

    /**
     * Whether a slot is unoccupied by an object of the given type.
     * @see PlanetPositions::isPositionFree()
     */
    public function isPositionFree(int $universe, int $galaxy, int $system, int $planet, string $type = TYPE_PLANET): bool
    {
        return $this->planetPositions()->isPositionFree($universe, $galaxy, $system, $planet, $type);
    }

    /**
     * Validates a player or planet name against the allowed character set.
     * @see PlayerRegistration::isNameValid()
     */
    public function isNameValid(string $name, bool $isPlanetName = false): bool
    {
        return $this->playerRegistration()->isNameValid($name, $isPlanetName);
    }

    /**
     * Whether the coordinates lie inside the universe size (1..max_galaxy/max_system/max_planets).
     * @see PlanetPositions::checkPosition()
     */
    public function checkPosition(int $universe, int $galaxy, int $system, int $planet): bool
    {
        return $this->planetPositions()->checkPosition($universe, $galaxy, $system, $planet);
    }

    /**
     * Position-band planet data (size, temperature, resource bonus per planet position): the generic rows (universe 0) overridden per position by the rows of the current universe.
     * @see PlanetManager::getPlanetDataPlanetConfig()
     */
    public function getPlanetDataPlanetConfig(): array
    {
        return $this->planetManager()->getPlanetDataPlanetConfig();
    }

    /**
     * Chooses a position for a new home planet by the planet_creation setting: 1 = random, 2 = random or block (coin flip), anything else = linear.
     * @see PlanetPositions::getNewPlanetPosition()
     */
    public function getNewPlanetPosition(int $universe): array
    {
        return $this->planetPositions()->getNewPlanetPosition($universe);
    }

    /**
     * Creates a player (user, settings, home planet, statistics row, account link) in a universe.
     * @see PlayerRegistration::createPlayer()
     */
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

    /**
     * Creates a planet (planet_type 1) at a free position and returns its id.
     * @see PlanetManager::createPlanet()
     */
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

    /**
     * Creates the moon of a planet and links it (parent planet id_luna).
     * @see PlanetManager::createMoon()
     */
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

    /**
     * Removes a player from their alliance and drops pending applications.
     * @see PlayerDeletion::removeFromAlliance()
     */
    public function removeFromAlliance(array $userData): void
    {
        $this->playerDeletion()->removeFromAlliance($userData);
    }

    /**
     * Deletes all buddy relations and requests of a player (as owner or sender).
     * @see PlayerDeletion::removeFromBuddy()
     */
    public function removeFromBuddy(int $userId): void
    {
        $this->playerDeletion()->removeFromBuddy($userId);
    }

    /**
     * Deletes a player and everything owned by them (planets, fleets, messages, stats, settings, account link).
     * @see PlayerDeletion::deletePlayer()
     */
    public function deletePlayer(int $userId): bool
    {
        return $this->playerDeletion()->deletePlayer($userId);
    }

    /**
     * Deletes every planet of all regular players of a universe and gives each a new home planet.
     * @see PlanetReshuffle::reshuffleAllPlanets()
     */
    public function reshuffleAllPlanets(int $universe): string|bool
    {
        return $this->planetReshuffle()->reshuffleAllPlanets($universe);
    }

    /**
     * Deletes a planet or moon.
     * @see PlanetManager::deletePlanet()
     */
    public function deletePlanet(int $planetId): bool
    {
        return $this->planetManager()->deletePlanet($planetId);
    }

    /**
     * Number of planets a player may own: max_initial_planets plus planets_per_tech per astrophysics level, capped by max_additional_planets (a universe without initial planets removes the cap).
     * @see PlanetPositions::maxPlanetCount()
     */
    public function maxPlanetCount(array $USER): int
    {
        return $this->planetPositions()->maxPlanetCount($USER);
    }

    /**
     * Number of non-destroyed planets (not moons) the user owns.
     * @see PlanetPositions::currentPlanetCount()
     */
    public function currentPlanetCount(array $USER): int
    {
        return $this->planetPositions()->currentPlanetCount($USER);
    }

    /**
     * Whether a player may settle a slot with the current astrophysics level.
     * @see PlanetPositions::allowPlanetPosition()
     */
    public function allowPlanetPosition(int $position, ?array $USER = null, int $universe = ROOT_UNI): bool
    {
        return $this->planetPositions()->allowPlanetPosition($position, $USER, $universe);
    }

    /**
     * Forwards an existing message to more users (users_to_messages copies marked forwarded, unread). INSERT IGNORE, so users who already hold a copy are skipped. The forwarder is the current user.
     * @see PlayerMessenger::forwardMessage()
     */
    public function forwardMessage(int|array $userId, int $senderId, int $messageId): void
    {
        $this->playerMessenger()->forwardMessage($userId, $senderId, $messageId);
    }

    /**
     * Stores a message and delivers it to one or more users.
     * @see PlayerMessenger::sendMessage()
     */
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

    /**
     * Sets resources, ships, defense and the hangar queue to zero on every planet of a user (reset helper).
     * @see PlanetManager::clearPlanets()
     */
    public function clearPlanets(array $USER): void
    {
        $this->planetManager()->clearPlanets($USER);
    }

    /**
     * Ends vacation mode once the minimum duration has passed.
     * @see VacationMode::disable_vmode()
     */
    public function disable_vmode(array &$USER, ?array &$PLANET = null): void
    {
        $this->vacationMode()->disable_vmode($USER, $PLANET);
    }

    /**
     * Starts vacation mode: sets urlaubs_modus/start (and urlaubs_until = now + vmode_min_time, or 0 when caused by a ban), zeroes production and energy on all planets and sends back fleets that hold at the user's planets.
     * @see VacationMode::enable_vmode()
     */
    public function enable_vmode(array &$USER, ?array &$PLANET, bool $byBan = false): void
    {
        $this->vacationMode()->enable_vmode($USER, $PLANET, $byBan);
    }

    /**
     * Mission colour settings of a player.
     * @see PlayerLookup::player_colors()
     */
    public function player_colors(?array $USER = null): array
    {
        return $this->playerLookup()->player_colors($USER);
    }

    /**
     * Small/medium/big thresholds of the stb (quick fleet) display. Defaults without a user; otherwise the user's cached `stb_settings` entry or the stb_* columns.
     * @see PlayerLookup::player_stb_settings()
     */
    public function player_stb_settings(?array $USER = null): array
    {
        return $this->playerLookup()->player_stb_settings($USER);
    }

    /**
     * Positive/negative/neutral signal colours. Defaults without a user; otherwise the cached `signalColors` entry or the color* columns.
     * @see PlayerLookup::player_signal_colors()
     */
    public function player_signal_colors(?array $USER = null): array
    {
        return $this->playerLookup()->player_signal_colors($USER);
    }

    /**
     * Whether two players are buddies (accepted relation in either direction).
     * @see PlayerLookup::get_buddy_status()
     */
    public function get_buddy_status(int $userId, int $targetUserId): bool
    {
        return $this->playerLookup()->get_buddy_status($userId, $targetUserId);
    }

    /**
     * Whether $targetUserId blocked $userId for direct messages and trade.
     * @see PlayerLookup::get_block_status()
     */
    public function get_block_status(int $userId, int $targetUserId): array
    {
        return $this->playerLookup()->get_block_status($userId, $targetUserId);
    }

    /**
     * Auth level of a user (0 = player, higher = staff).
     * @see PlayerLookup::get_authlevel()
     */
    public function get_authlevel(int $userId): int
    {
        return $this->playerLookup()->get_authlevel($userId);
    }

    /**
     * Account id a user belongs to, 0 if there is none.
     * @see PlayerLookup::get_account_id()
     */
    public function get_account_id(int $userId): int
    {
        return $this->playerLookup()->get_account_id($userId);
    }

    /**
     * Calculate and update honorpoint changes for 1 vs 1 fights
     * @see HonorPoints::calculate_honorpoints_single()
     */
    public function calculate_honorpoints_single(int $attacker, int $defender, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        return $this->honorPoints()->calculate_honorpoints_single($attacker, $defender, $attackerUnitsLost, $defenderUnitsLost);
    }

    /**
     * Calculate and update the change in honorpoints for multiple attackers and defenders(ACS) Points get distributed proportional to the ships send(points) Player needs to add more than 1% of the total points to get honorpoints
     * @see HonorPoints::calculate_honorpoints_multi()
     */
    public function calculate_honorpoints_multi(array $attackers, array $defenders, int $attackerUnitsLost, int $defenderUnitsLost): array
    {
        return $this->honorPoints()->calculate_honorpoints_multi($attackers, $defenders, $attackerUnitsLost, $defenderUnitsLost);
    }

    /**
     * Retrieve the honorpoints and the number of honorable and dishonorable fights of a player
     * @see HonorPoints::get_honor_data()
     */
    public function get_honor_data(int $userId): array
    {
        return $this->honorPoints()->get_honor_data($userId);
    }

    /**
     * Calculate good effect of honorpoints (decrease deut costs for attacks on players with high honorpoints) can be 0.80(20%), 0.88(12%), 0.95(5%) or 1(0%) decrease
     * @see HonorPoints::calculate_honorpoints_boost()
     */
    public function calculate_honorpoints_boost(int $userId): float
    {
        return $this->honorPoints()->calculate_honorpoints_boost($userId);
    }

    /**
     * Calculate bad effect of honorpoints (increase loot for attacks on players with low honorpoints) can be 1(50%), 1.2(60%), 1.6(80%) or 2(100%) increase
     * @see HonorPoints::calculate_honorpoints_decrease()
     */
    public function calculate_honorpoints_decrease(int $userId): float
    {
        return $this->honorPoints()->calculate_honorpoints_decrease($userId);
    }

    /** @deprecated use catchUpFactorFor() with a typed User */
    public function getCatchUpFactor(array $USER): float
    {
        return $this->protectionRules()->getCatchUpFactor($USER);
    }

    /** Typed catch-up cost factor of a player, see ProtectionRules::catchUpFactorFor(). */
    public function catchUpFactorFor(\App\Game\Model\User $user): float
    {
        return $this->protectionRules()->catchUpFactorFor($user);
    }

    /**
     * Catch-up factor of one element: 1 at or above the record level, 2/3 and 1/3 of the way to the base factor when the player is 1 or 2 levels behind the highest level in records, otherwise the base factor.
     * @see ProtectionRules::getCatchUpFactorForElement()
     */
    public function getCatchUpFactorForElement(float $factor, int $elementId, int $currentLevel): float
    {
        return $this->protectionRules()->getCatchUpFactorForElement($factor, $elementId, $currentLevel);
    }

    /** @deprecated use spyTechAllowedFor() with a typed User */
    public function spyTechAllowed(int $necessaryLevel, int $PlayerID, array $USER): bool
    {
        return $this->protectionRules()->spyTechAllowed($necessaryLevel, $PlayerID, $USER);
    }

    /** Typed spy-tech visibility check, see ProtectionRules::spyTechAllowedFor(). */
    public function spyTechAllowedFor(int $necessaryLevel, int $PlayerID, \App\Game\Model\User $viewer): bool
    {
        return $this->protectionRules()->spyTechAllowedFor($necessaryLevel, $PlayerID, $viewer);
    }

    /**
     * Points history of a player for charts: time labels, total/build/tech/fleet/defence points (hidden values are 0 when the viewer lacks spy tech), the viewer's own total points at the same timestamps and relative/absolute score changes.
     * @see PlayerCharts::getChartPoints()
     */
    public function getChartPoints(int $targetUserId, array $USER, int $timespan = TIME_1_WEEK): array
    {
        return $this->playerCharts()->getChartPoints($targetUserId, $USER, $timespan);
    }

    /**
     * Total points history of all members of an alliance for charts.
     * @see PlayerCharts::getAllianceChartPoints()
     */
    public function getAllianceChartPoints(int $allyId, array $USER, int $timespan = TIME_1_MONTH * 12): array
    {
        return $this->playerCharts()->getAllianceChartPoints($allyId, $USER, $timespan);
    }

    /**
     * User row joined with the player's total statpoints row (stat_type 1).
     * @see PlayerLookup::getPlayerById()
     */
    public function getPlayerById(int $playerId): ?array
    {
        return $this->playerLookup()->getPlayerById($playerId);
    }

    /**
     * Universe id of a user.
     * @see PlayerLookup::getUniverse()
     */
    public function getUniverse(int $userId): int
    {
        return $this->playerLookup()->getUniverse($userId);
    }

    /**
     * Language object with the ingame data domains loaded, for the given language or the language of the given user.
     * @see PlayerLookup::getLanguage()
     */
    public function getLanguage($language = null, $userID = null)
    {
        return $this->playerLookup()->getLanguage($language, $userID);
    }

    /**
     * Non-destroyed planets and moons of a user for lists/menus, ordered by the user's planet_sort settings (id, coordinates, name; ascending or descending). Returns the cached `PLANETS` entry of the user row if present.
     * @see PlanetQueries::getPlanets()
     */
    public function getPlanets($USER)
    {
        return $this->planetQueries()->getPlanets($USER);
    }

    /**
     * The user's main planet row by the home coordinates stored on the user.
     * @see PlanetQueries::getMainPlanet()
     */
    public function getMainPlanet(array $user): array
    {
        return $this->planetQueries()->getMainPlanet($user);
    }

    /**
     * Maximum number of building fields of a planet: base size plus terraformer and moon base bonus.
     * @see PlanetQueries::calculateMaxPlanetFields()
     */
    public function calculateMaxPlanetFields($planet)
    {
        return $this->planetQueries()->calculateMaxPlanetFields($planet);
    }

    /**
     * Typed version of calculateMaxPlanetFields(), optionally with other terraformer / moon base levels.
     * @see PlanetQueries::maxFieldsFor()
     */
    public function maxFieldsFor(\App\Game\Model\Planet $planet, int|float|null $terraformerLevel = null, int|float|null $moonBaseLevel = null): int|float
    {
        return $this->planetQueries()->maxFieldsFor($planet, $terraformerLevel, $moonBaseLevel);
    }

    /**
     * status classes of a player row (banned, vacation, inactive, noob, strong) for lists and the galaxy
     * @see ProtectionRules::userStatus()
     */
    public function userStatus(array $data, bool|array $noob_protection = false): array
    {
        return $this->protectionRules()->userStatus($data, $noob_protection);
    }

    /** @deprecated use checkNoobProtectionFor() with typed Users */
    public function checkNoobProtection($OwnerPlayer, $TargetPlayer)
    {
        return $this->protectionRules()->checkNoobProtection($OwnerPlayer, $TargetPlayer);
    }

    /**
     * Typed noob/strong protection between two players, see ProtectionRules::checkNoobProtectionFor().
     *
     * @return array{NoobPlayer: bool, StrongPlayer: bool}
     */
    public function checkNoobProtectionFor(\App\Game\Model\User $owner, \App\Game\Model\User $target): array
    {
        return $this->protectionRules()->checkNoobProtectionFor($owner, $target);
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
