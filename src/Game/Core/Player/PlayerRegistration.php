<?php

namespace App\Game\Core\Player;

use Exception;
use App\Game\AdvancedStats\AdvancedStatsEnum;
use App\Game\AdvancedStats\AdvancedStatsHandler;
use App\Game\Core\Theme;
use App\Game\Core\Database;
use App\Game\Core\ConfigRegistry;
use App\Game\Core\Singleton;
use App\Game\Core\NumberAbbreviation;
use App\Game\Core\Config;
use App\Game\Core\GameUtilities;

/**
 * Creates players (users, settings, home planet, statistics, account link) and validates names. Collaborators:
 * PlanetPositions, PlanetManager, AdvancedStatsHandler, ConfigRegistry (users_amount), SessionManager (client
 * IP).
 */
class PlayerRegistration
{
    use GameUtilities;

    public function __construct(
        protected Database $db,
        protected ConfigRegistry $configs,
        protected Singleton $state,
    ) {}

    /**
     * Creates a player (user, settings, home planet, statistics row, account link) in a universe.
     *
     * Position: the given galaxy/system/planet, or a new one by the universe's planet position strategy.
     * Settings (stb, colours, mission priorities) can be copied from the account's player in another
     * universe (pass that universe's id; "default" or null keeps the defaults).
     * Side effects: INSERT users, users_settings, planet, statpoints (rank = last rank + 1),
     * accounts_to_users; users_amount of the universe config is incremented and saved; initial honor points 100.
     *
     * @param int $accountId account the player belongs to
     * @param int $universe
     * @param string $userName
     * @param string|null $userLanguage
     * @param int|null $galaxy fixed start position (all three or none)
     * @param int|null $system
     * @param int|null $planet
     * @param string|null $name home planet name
     * @param int $authlevel
     * @param string|null $userIpAddress registration IP, default: client IP
     * @param string|null $stb source universe id for stb settings, or "default"
     * @param string|null $colorSettings source universe id for colour settings, or "default"
     * @param string|null $missionSettings source universe id for mission priorities, or "default"
     * @param string|null $timezone default: universe timezone
     * @param string|null $imageTheme default: universe default theme
     * @param string|null $cssTheme default: universe default theme
     * @return array{0: int, 1: int} [user id, home planet id]
     * @throws Exception when a given position is outside the universe or taken
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
        $config = $this->configs->get($universe);
        $imageTheme ??= Theme::resolveDefaultTheme($config->default_theme ?? null);
        $cssTheme ??= Theme::resolveDefaultTheme($config->default_theme ?? null);
        $db = $this->db;

        $pos = $this->startPosition($universe, $galaxy, $system, $planet);

        $userId = $this->insertUserRecords(
            $config,
            $universe,
            $userName,
            $authlevel,
            $userIpAddress,
            $userLanguage,
            $timezone,
            $imageTheme,
            $cssTheme,
        );

        $this->copySettingsFromOtherUniverse($userId, $accountId, $stb, $colorSettings, $missionSettings);

        $planetId = $this->planetManager()->createPlanet(
            $pos['galaxy'],
            $pos['system'],
            $pos['planet'],
            $universe,
            $userId,
            $name,
            true,
            $authlevel,
        );

        $currentUserAmount = $config->users_amount + 1;
        $config->users_amount = $currentUserAmount;

        $sql = 'UPDATE %%USERS%% SET
            `galaxy` = :galaxy,
            `system` = :system,
            `planet` = :planet,
            `id_planet` = :planetId
            WHERE `id` = :userId;';

        $db->update($sql, [
            ':galaxy'   => $pos['galaxy'],
            ':system'   => $pos['system'],
            ':planet'   => $pos['planet'],
            ':planetId' => $planetId,
            ':userId'   => $userId,
        ]);

        $this->insertStatpointsRow($userId, $universe);

        $sql = 'INSERT INTO %%ACCOUNTS_TO_USERS%% SET
            `account_id`    = :accId,
            user_id         = :userId,
            end_time        = 0;';
        $db->insert($sql, [
            ':userId'    => $userId,
            ':accId'    => $accountId,
        ]);
        $config->save();

        return [
            $userId,
            $planetId,
        ];
    }

    /**
     * Start position of a new player: the given coordinates (validated) or a freshly chosen free one.
     *
     * @param int $universe
     * @param int|null $galaxy
     * @param int|null $system
     * @param int|null $planet
     * @return array{galaxy: int, system: int, planet: int}
     * @throws Exception when the given position is invalid or not free
     */
    private function startPosition(int $universe, ?int $galaxy, ?int $system, ?int $planet): array
    {
        if (isset($universe, $galaxy, $system, $planet)) {
            $pos = [
                'galaxy'   => $galaxy,
                'system'   => $system,
                'planet' => $planet,
            ];
        } else {
            $pos = $this->planetPositions()->getNewPlanetPosition($universe);
        }

        if (isset($universe, $galaxy, $system, $planet)) {
            if ($this->planetPositions()->checkPosition($universe, $galaxy, $system, $planet) === false) {
                throw new Exception(
                    sprintf("Try to create a planet at position: %s:%s:%s!", $galaxy, $system, $planet),
                );
            }

            if ($this->planetPositions()->isPositionFree($universe, $galaxy, $system, $planet) === false) {
                throw new Exception(
                    sprintf("Position is not empty: %s:%s:%s!", $galaxy, $system, $planet),
                );
            }
        }

        return $pos;
    }

    /**
     * Inserts the users row, the initial honor points and the users_settings row of a new player.
     *
     * @param Config $config universe configuration (default timezone)
     * @return int id of the new user
     */
    private function insertUserRecords(
        Config $config,
        int $universe,
        string $userName,
        int $authlevel,
        ?string $userIpAddress,
        ?string $userLanguage,
        ?string $timezone,
        ?string $imageTheme,
        ?string $cssTheme,
    ): int {
        $db = $this->db;

        $params = [
            ':username'          => $userName,
            ':authlevel'         => $authlevel,
            ':universe'          => $universe,
            ':registerAddress'   => !empty($userIpAddress) ? $userIpAddress : $this->sessions()->getClientIp(),
            ':onlinetime'        => $this->state->runtime->timestamp,
            ':registerTimestamp' => $this->state->runtime->timestamp,
            ':nameLastChanged'   => 0,
        ];

        $sql = 'INSERT INTO %%USERS%% SET
            `username`		= :username,
            `authlevel`		= :authlevel,
            `universe`		= :universe,
            `ip_at_reg`		= :registerAddress,
            `onlinetime`	= :onlinetime,
            `register_time` = :registerTimestamp,
            `uctime`		= :nameLastChanged;';

        $db->insert($sql, $params);

        $userId = $db->lastInsertId();

        $statsHandler = new AdvancedStatsHandler($this->db, $this->configs, $this->state);
        $statsHandler->setValue($userId, AdvancedStatsEnum::HONORPOINTS, 100);

        $timezone = $timezone ?? $config->timezone;
        $params = [
            ':userId'            => $userId,
            ':language'          => $userLanguage,
            ':dpath'             => $imageTheme,
            ':css_dpath'         => $cssTheme,
            ':timezone'          => $timezone,
            ':decimalSeparator'  => (new NumberAbbreviation())->defaultDecimalSeparatorForLang($userLanguage),
        ];

        $sql = 'INSERT INTO %%USERS_SETTINGS%% SET
            `user_id`		= :userId,
            `lang`			= :language,
            `dpath`			= :dpath,
            `css_dpath`    = :css_dpath,
            `timezone`		= :timezone,
            `decimal_separator` = :decimalSeparator;';

        $db->insert($sql, $params);

        return $userId;
    }

    /**
     * Copies stb, colour and mission-priority settings from the account's player in another universe.
     * Each argument is the id of the source universe, or null/"default" to keep the defaults.
     *
     * @param int $userId target user
     * @param int $accountId
     * @param string|null $stb
     * @param string|null $colorSettings
     * @param string|null $missionSettings
     */
    private function copySettingsFromOtherUniverse(int $userId, int $accountId, ?string $stb, ?string $colorSettings, ?string $missionSettings): void
    {
        // copy settings from the account's player in another universe (the value is that universe's id)
        if ($stb != null && $stb != "default") {
            $this->copyUserSettings($userId, $accountId, (int) $stb, [
                'stb_small_ress', 'stb_med_ress', 'stb_big_ress',
                'stb_small_time', 'stb_med_time', 'stb_big_time', 'stb_enabled',
            ]);
        }
        if ($colorSettings != null && $colorSettings != "default") {
            $this->copyUserSettings($userId, $accountId, (int) $colorSettings, [
                'colorMission2friend', 'colorMission1Own', 'colorMission2Own', 'colorMission3Own',
                'colorMission4Own', 'colorMission5Own', 'colorMission6Own', 'colorMission7Own',
                'colorMission7OwnReturn', 'colorMission8Own', 'colorMission9Own', 'colorMission10Own',
                'colorMission15Own', 'colorMission16Own', 'colorMission17Own', 'colorMissionReturnOwn',
                'colorMission1Foreign', 'colorMission2Foreign', 'colorMission3Foreign',
                'colorMission4Foreign', 'colorMission5Foreign', 'colorMission6Foreign',
                'colorMission7Foreign', 'colorMission8Foreign', 'colorMission9Foreign',
                'colorMission10Foreign', 'colorMission15Foreign', 'colorMission16Foreign',
                'colorMission17Foreign', 'colorMissionReturnForeign', 'colorStaticTimer',
                'colorPositive', 'colorNegative', 'colorNeutral',
            ]);
        }
        if ($missionSettings != null && $missionSettings != "default") {
            $this->copyUserSettings($userId, $accountId, (int) $missionSettings, [
                'prioMission1', 'prioMission2', 'prioMission3', 'prioMission4', 'prioMission5',
                'prioMission6', 'prioMission7', 'prioMission8', 'prioMission9', 'prioMission17',
            ]);
        }
    }

    /**
     * Adds the statistics row of a new player; ranks start behind the current last total rank.
     *
     * @param int $userId
     * @param int $universe
     */
    private function insertStatpointsRow(int $userId, int $universe): void
    {
        $db = $this->db;

        $sql = 'SELECT MAX(`total_rank`) as `rank` FROM %%STATPOINTS%% WHERE `universe` = :universe AND `stat_type` = :type;';
        $rank = $db->selectSingle($sql, [
            ':universe' => $universe,
            ':type'     => 1,
        ], 'rank');

        $sql = "INSERT INTO %%STATPOINTS%% SET
            `id_owner`   = :userId,
            `universe`   = :universe,
            `stat_type`  = :type,
            `tech_rank`  = :rank,
            `build_rank` = :rank,
            `defs_rank`	 = :rank,
            `fleet_rank` = :rank,
            `total_rank` = :rank;";

        $db->insert($sql, [
            ':universe'  => $universe,
            ':userId'    => $userId,
            ':type'      => 1,
            ':rank'      => $rank + 1,
        ]);
    }

    /**
     * Copies the given users_settings columns from the account's player in $sourceUniverse to $userId.
     * Only players of the same account are used as source.
     *
     * @param string[] $columns fixed column names (never user input)
     */
    private function copyUserSettings(int $userId, int $accountId, int $sourceUniverse, array $columns): void
    {
        $assignments = [];
        foreach ($columns as $column) {
            $assignments[] = 'dest.`' . $column . '` = src.`' . $column . '`';
        }

        $sql = 'UPDATE %%USERS_SETTINGS%% dest
            JOIN (
                SELECT us.*
                FROM %%USERS_SETTINGS%% us
                INNER JOIN %%ACCOUNTS_TO_USERS%% atu ON us.`user_id` = atu.`user_id`
                INNER JOIN %%USERS%% u ON us.`user_id` = u.`id`
                WHERE atu.`account_id` = :accId AND u.`universe` = :universe AND us.`user_id` != :userId
                LIMIT 1
            ) AS src
            SET ' . implode(', ', $assignments) . '
            WHERE dest.`user_id` = :userId;';

        $this->db->update($sql, [
            ':accId' => $accountId,
            ':universe' => $sourceUniverse,
            ':userId' => $userId,
        ]);
    }

    /**
     * Validates a player or planet name against the allowed character set.
     *
     * Planet names allow Unicode letters/digits and `_ - . space`; player names allow ASCII letters, digits,
     * German umlauts, ß and `space _ . -`.
     *
     * @param string $name
     * @param bool $isPlanetName true for planet names
     * @return bool true if the name consists of allowed characters only
     */
    public function isNameValid(string $name, bool $isPlanetName = false): bool
    {
        if (UTF8_SUPPORT && $isPlanetName) {
            return preg_match('/^[\p{L}\p{N}_\-. ]*$/u', $name);
        } elseif (UTF8_SUPPORT) {
            return preg_match('/^[0-9a-zA-ZäöüÄÖÜß _.-]*$/u', $name);
        } else {
            return preg_match('/^[A-z0-9_\-. ]*$/', $name);
        }
    }

    private function planetManager(): PlanetManager
    {
        return $this->gameUtility(PlanetManager::class);
    }

    private function planetPositions(): PlanetPositions
    {
        return $this->gameUtility(PlanetPositions::class);
    }
}
